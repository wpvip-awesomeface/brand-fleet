<?php
/**
 * Integration test for opening hours, holiday closures, list variables, local SEO
 * schema, values export, value history, the overrides report, undo, change requests
 * and alerts.
 *
 * Run only on a disposable local multisite (localhost or a VIP dev-env *.lndo.site)
 * with Brand Fleet network-activated:
 *   wp eval-file tests/trust-local-seo.php
 * It creates sites, users, posts and definitions and leaves them; reset the network after.
 */
use BrandFleet\Fleet;
use BrandFleet\Fleet_Alerts;
use BrandFleet\Fleet_Export;
use BrandFleet\Fleet_History;
use BrandFleet\Fleet_Hours;
use BrandFleet\Fleet_Import;
use BrandFleet\Fleet_Jobs;
use BrandFleet\Fleet_Overrides;
use BrandFleet\Fleet_Requests;
use BrandFleet\Fleet_Schema;
use BrandFleet\Fleet_Segments;
use BrandFleet\Fleet_Undo;
use BrandFleet\Settings;
$host = (string) wp_parse_url( network_home_url(), PHP_URL_HOST );
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! is_multisite() || ! ( str_ends_with( $host, 'localhost' ) || str_ends_with( $host, '.lndo.site' ) ) ) {
	exit( 1 );
}
if ( ! function_exists( 'wpvip_purge_edge_cache_for_post' ) ) {
	function wpvip_purge_edge_cache_for_post( $page_one ) { unset( $page_one ); }
}
function ok( $cond, $msg ) { WP_CLI::log( ( $cond ? 'PASS ' : 'FAIL ' ) . $msg ); }
function throws( callable $fn ): bool {
	try { $fn(); return false; } catch ( \InvalidArgumentException | \RuntimeException $e ) { return true; }
}
function run_job( array $j, bool $confirm = false ): array {
	for ( $i = 0; $i < 200; ++$i ) {
		if ( 'ready' === $j['phase'] && ! $confirm ) { return $j; }
		if ( ! in_array( $j['phase'], array( 'preview', 'ready', 'apply' ), true ) ) { return $j; }
		$j = Fleet_Jobs::step( $j['id'], (int) $j['cursor'], $confirm );
	}
	return $j;
}
function def( string $key, array $d ): void { Fleet::save_definition( $key, $d + array( 'label' => $key, 'scope' => 'location', 'access' => 'none' ) ); }
function site( string $slug ): int { return (int) wpmu_create_blog( (string) wp_parse_url( network_home_url(), PHP_URL_HOST ), '/' . $slug . '/', $slug, get_current_user_id() ); }
function head_of( int $site, bool $front = true ): string {
	switch_to_blog( $site );
	Settings::flush();
	global $wp_query;
	$wp_query->is_home = $front; $wp_query->is_front_page = $front;
	add_filter( 'pre_option_show_on_front', static fn() => 'posts' );
	ob_start(); ( new \BrandFleet\Fleet_Schema() )->print(); $out = (string) ob_get_clean();
	restore_current_blog();
	return $out;
}
$admin = get_user_by( 'login', current( get_super_admins() ) )->ID;
wp_set_current_user( $admin );

// --- Opening hours and lists.
def( 'province', array( 'type' => 'select', 'options' => "ON\nQC" ) );
def( 'location_name', array( 'type' => 'text', 'label' => 'Location name' ) );
def( 'opening_hours', array( 'type' => 'hours', 'label' => 'Opening hours', 'default' => 'Mon-Fri 9-5' ) );
def( 'services', array( 'type' => 'list', 'label' => 'Services' ) );
def( 'city', array( 'type' => 'text' ) );
def( 'phone_main', array( 'type' => 'phone' ) );
ok( 'Mon 09:00-17:00; Tue 09:00-17:00; Wed 09:00-17:00; Thu 09:00-17:00; Fri 09:00-17:00; Sat closed; Sun closed' === Fleet::definitions()['opening_hours']['default'], 'hours default stored in canonical form' );
ok( throws( static fn() => def( 'bad_hours', array( 'type' => 'hours', 'default' => 'Mon 9-' ) ) ), 'unreadable hours are rejected' );
$a = site( 'kingston' ); $b = site( 'montreal' );
Fleet::save_profile( $a, array( 'province' => 'ON', 'location_name' => 'Kingston Dental', 'opening_hours' => "Mon-Thu 8am-6pm\nFri 8-2\nSat 9-1", 'services' => "Cleanings\nImplants\n\nWhitening", 'city' => 'Kingston', 'phone_main' => '613-555-0100' ), array() );
Fleet::save_profile( $b, array( 'province' => 'QC', 'location_name' => 'Montréal Dentaire', 'services' => 'Cleanings | Braces' ), array() );
Fleet_Segments::save( array( array( 'slug' => 'quebec', 'label' => 'Quebec', 'rules' => array( 'province' => array( 'QC' ) ) ) ), Fleet::hash( Fleet_Segments::all() ) );
ok( "Cleanings\nImplants\nWhitening" === Fleet::profile( $a )['values']['services'], 'list drops empty lines' );
ok( "Cleanings\nBraces" === Fleet::profile( $b )['values']['services'], 'list accepts a | separated cell' );
switch_to_blog( $a ); Settings::flush();
ok( 'Cleanings, Implants, Whitening' === ( Settings::tokens()['{{services}}'] ?? '' ), 'list token prints a comma list' );
ok( str_starts_with( (string) ( Settings::tokens()['{{opening_hours}}'] ?? '' ), 'Mon–Thu 8:00 am–6:00 pm' ), 'hours token prints grouped days' );
$html = render_block( array( 'blockName' => 'brand-fleet/hours', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) );
ok( str_contains( $html, 'data-intervals' ) && str_contains( $html, 'Fri</th><td>8:00 am–2:00 pm' ), 'hours block renders the week and open-now data' );
$html = render_block( array( 'blockName' => 'brand-fleet/list', 'attrs' => array( 'ordered' => true ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) );
ok( str_contains( $html, '<ol' ) && str_contains( $html, '<li>Implants</li>' ), 'list block renders items' );
restore_current_blog();

// --- Holiday closures by segment.
$tomorrow = ( new \DateTimeImmutable( 'tomorrow', wp_timezone() ) )->format( 'Y-m-d' );
Fleet_Hours::save_closures( array( array( 'date' => $tomorrow, 'hours' => '', 'label' => 'Fête nationale', 'segment' => 'quebec' ) ) );
ok( throws( static fn() => Fleet_Hours::save_closures( array( array( 'date' => $tomorrow, 'segment' => 'nowhere' ) ) ) ), 'closure segment must exist' );
switch_to_blog( $b ); $mtl = Fleet_Hours::on( $b, Fleet_Hours::parse( Fleet::definitions()['opening_hours']['default'] ), $tomorrow ); restore_current_blog();
switch_to_blog( $a ); $kgn = Fleet_Hours::on( $a, Fleet_Hours::parse( (string) Fleet::resolved( $a, 'opening_hours' )['value'] ), $tomorrow ); restore_current_blog();
ok( array() === $mtl[0] && 'Fête nationale' === $mtl[1], 'segment closure closes Quebec sites' );
ok( 'Fête nationale' !== $kgn[1], 'segment closure leaves other sites open' );

// --- Local SEO schema.
ok( '' === head_of( $a ), 'no schema until local SEO is turned on' );
Fleet_Schema::save( array( 'enabled' => 1, 'type' => 'Dentist', 'where' => 'home', 'map' => array( 'name' => 'location_name', 'telephone' => 'phone_main', 'locality' => 'city', 'hours' => 'opening_hours' ) ) );
$out = head_of( $a );
$json = json_decode( (string) preg_replace( '#^\s*<script[^>]*>|</script>\s*$#', '', $out ), true );
ok( 'Dentist' === ( $json['@type'] ?? '' ) && 'Kingston Dental' === ( $json['name'] ?? '' ) && 'Kingston' === ( $json['address']['addressLocality'] ?? '' ), 'schema has type, name and address' );
ok( in_array( 'Monday', $json['openingHoursSpecification'][0]['dayOfWeek'] ?? array(), true ), 'schema has weekly hours' );
ok( '' === head_of( $a, false ), 'home-page-only schema skips other pages' );
$json = json_decode( (string) preg_replace( '#^\s*<script[^>]*>|</script>\s*$#', '', head_of( $b ) ), true );
ok( (bool) array_filter( $json['openingHoursSpecification'] ?? array(), static fn( $s ) => ( $s['validFrom'] ?? '' ) === $tomorrow && '00:00' === $s['closes'] ), 'schema lists the Quebec holiday closure' );
Fleet::save_profile( $a, array( 'location_name' => 'Kingston </script><script>alert(1)</script>' ) );
ok( ! str_contains( head_of( $a ), '</script><script>' ), 'schema output cannot break out of its script tag' );
ok( throws( static fn() => Fleet_Schema::save( array( 'enabled' => 1, 'map' => array( 'name' => 'opening_hours' ) ) ) ), 'schema fields only accept fitting variable types' );

// --- Values export round-trips through import.
Fleet::save_profile( $a, array( 'location_name' => '=HYPERLINK("http://evil")' ) );
$csv = Fleet_Export::csv( array( $a, $b ) );
$lines = explode( "\r\n", trim( $csv ) );
ok( str_starts_with( $lines[0], 'site_id,domain,' ) && 3 === count( $lines ), 'export has a header and one row per site' );
ok( str_contains( $csv, "\"'=HYPERLINK(\"\"http://evil\"\")\"" ), 'export guards formulas with an apostrophe' );
ok( str_contains( $csv, '"Cleanings' . "\n" . 'Implants' ), 'export keeps list items in one quoted cell' );
$plan = Fleet_Import::plan( Fleet_Import::parse( $csv ), 'site_id' );
ok( '=HYPERLINK("http://evil")' === ( $plan['plan'][ $a ]['location_name'] ?? '' ) && "Cleanings\nImplants\nWhitening" === ( $plan['plan'][ $a ]['services'] ?? '' ), 'exported values import back unchanged' );
ok( ! isset( $plan['plan'][ $a ]['opening_hours'] ) || '' !== $plan['plan'][ $a ]['opening_hours'], 'empty export cells leave values alone' );
$shown = Fleet_Export::csv( array( $b ), 'shown' );
ok( str_contains( $shown, 'Mon 09:00-17:00' ), 'shown export includes shared values' );

// --- Value history and restore.
Fleet::save_profile( $a, array( 'city' => 'Kingston ON' ) );
Fleet::save_profile( $a, array( 'city' => null ) );
$log = Fleet_History::get( $a );
$last = end( $log );
ok( 'city' === $last['key'] && 'Kingston ON' === $last['old'] && null === $last['new'] && 'edit' === $last['via'], 'history records a reset to the shared value' );
Fleet_History::restore( $a, count( $log ) - 1, Fleet::hash( $last ) );
ok( 'Kingston ON' === ( Fleet::profile( $a )['values']['city'] ?? null ), 'restore puts the old value back' );
ok( 'restore' === end( Fleet_History::get( $a ) )['via'], 'restore is recorded in history' );
ok( throws( static fn() => Fleet_History::restore( $a, 0, 'stale' ) ), 'restore refuses a stale entry' );

// --- Overrides report.
Fleet_Overrides::backfill( 1000 );
ok( 1 === Fleet_Overrides::count( 'city' ) && array( $a ) === Fleet_Overrides::sites( 'city' ), 'overrides count sites with their own value' );
Fleet::save_profile( $a, array( 'city' => null ) );
ok( 0 === Fleet_Overrides::count( 'city' ), 'resetting a value clears its override marker' );
ok( 2 === Fleet_Overrides::count( 'location_name' ), 'every site with its own name is counted' );
$j = run_job( Fleet_Jobs::start( Fleet_Overrides::sites( 'services' ), array( 'services' => null ) ), true );
ok( 'complete' === $j['phase'] && 0 === Fleet_Overrides::count( 'services' ), 'bulk reset clears overrides' );
ok( 'bulk' === end( Fleet_History::get( $b ) )['via'], 'bulk changes are recorded as bulk in history' );

// --- Undo a bulk update, an import and a find and replace.
$c = site( 'ottawa' );
Fleet::save_profile( $c, array( 'city' => 'Ottawa', 'location_name' => 'Ottawa Dental' ), array() );
$j = run_job( Fleet_Jobs::start( array( $a, $c ), array( 'city' => 'Somewhere' ) ), true );
ok( 'complete' === $j['phase'] && Fleet_Undo::available( $j['id'] ), 'an applied bulk update can be undone' );
Fleet::save_profile( $c, array( 'city' => 'Ottawa West' ) );
$u = run_job( Fleet_Undo::start( $j['id'] ) );
ok( 'ready' === $u['phase'] && 1 === ( Fleet_Jobs::counts( $u )['skipped'] ?? 0 ), 'undo preview skips a site edited after the batch' );
$u = run_job( $u, true );
ok( ! array_key_exists( 'city', Fleet::profile( $a )['values'] ) && 'Ottawa West' === Fleet::profile( $c )['values']['city'], 'undo restores the shared value and leaves the later edit alone' );
ok( 'undo' === end( Fleet_History::get( $a ) )['via'], 'undo is recorded in history' );
ok( ! Fleet_Undo::available( $j['id'] ) && throws( static fn() => Fleet_Undo::start( $j['id'] ) ), 'a batch can be undone only once' );
$imp = Fleet_Import::start( "site_id,location_name\n{$c},Ottawa Centre", 'site_id', 'test' );
$imp = run_job( $imp, true );
ok( 'Ottawa Centre' === Fleet::profile( $c )['values']['location_name'], 'import applied' );
run_job( Fleet_Undo::start( $imp['id'] ), true );
ok( 'Ottawa Dental' === Fleet::profile( $c )['values']['location_name'], 'undo of an import puts the old value back' );
switch_to_blog( $c );
$page_one = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Call us', 'post_content' => '<p>Call 555-0199 today</p>' ) );
$page_two = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Visit', 'post_content' => '<p>Or 555-0199 tomorrow</p>' ) );
restore_current_blog();
$r = run_job( Fleet_Jobs::start_replace( array( $c ), array( 'find' => '555-0199', 'with' => '{{phone_main}}', 'types' => array( 'page' ) ) ), true );
switch_to_blog( $c );
ok( str_contains( get_post( $page_one )->post_content, '{{phone_main}}' ), 'find and replace applied' );
wp_update_post( array( 'ID' => $page_two, 'post_content' => '<p>Or {{phone_main}} tomorrow, edited</p>' ) );
restore_current_blog();
$u = run_job( Fleet_Undo::start( $r['id'] ), true );
switch_to_blog( $c );
ok( '<p>Call 555-0199 today</p>' === get_post( $page_one )->post_content, 'undo of find and replace restores the page' );
ok( str_contains( get_post( $page_two )->post_content, 'edited' ), 'undo of find and replace skips a page edited since' );
ok( ! get_post_meta( $page_one, Fleet_Undo::meta_key( $r['id'] ), true ), 'restored pages drop their snapshot' );
restore_current_blog();

// --- Change requests and alerts.
$sent = array();
add_filter( 'pre_wp_mail', static function ( $null, $mail ) use ( &$sent ) { $sent[] = $mail; return true; }, 10, 2 );
Fleet_Alerts::save( 'ops@example.com, lead@example.com', array_keys( Fleet_Alerts::EVENTS ) );
ok( throws( static fn() => Fleet_Alerts::save( 'not-an-email', array() ) ), 'alert emails are validated' );
$site_admin = wp_create_user( 'kingston-admin', wp_generate_password(), 'kadmin@example.com' );
add_user_to_blog( $a, $site_admin, 'administrator' );
wp_set_current_user( $site_admin );
ok( isset( Fleet_Requests::requestable( $a )['phone_main'] ), 'site admins can ask about values only the network edits' );
ok( throws( static fn() => Fleet_Requests::submit( $a, 'phone_main', 'not a phone!', '' ) ), 'requested values are validated' );
$req = Fleet_Requests::submit( $a, 'phone_main', '613-555-0111', 'New number since October' );
ok( 'pending' === $req['status'] && 1 === count( Fleet_Requests::pending() ), 'a request is waiting for review' );
ok( (bool) array_filter( $sent, static fn( $m ) => str_contains( $m['subject'], 'Change requested' ) && in_array( 'ops@example.com', (array) $m['to'], true ) ), 'a new request emails the alert list' );
ok( throws( static fn() => Fleet_Requests::submit( $b, 'phone_main', '613-555-0111', '' ) ), 'site admins can only ask for their own site' );
ok( throws( static fn() => Fleet_Requests::decide( $req['id'], true ) ), 'site admins cannot approve requests' );
wp_set_current_user( $admin );
Fleet_Requests::decide( $req['id'], true, 'Thanks' );
ok( '613-555-0111' === Fleet::profile( $a )['values']['phone_main'] && 'request' === end( Fleet_History::get( $a ) )['via'], 'approving sets the value and records it' );
ok( (bool) array_filter( $sent, static fn( $m ) => str_contains( $m['subject'], 'approved' ) && in_array( 'kadmin@example.com', (array) $m['to'], true ) ), 'the requester is told it was approved' );
ok( throws( static fn() => Fleet_Requests::decide( $req['id'], false ) ), 'a request is answered only once' );
$count = count( $sent );
Fleet_Alerts::import_failed( 'HTTP 404', $admin ); Fleet_Alerts::import_failed( 'HTTP 404', $admin );
ok( $count + 1 === count( $sent ), 'the same alert is sent at most once an hour' );

// --- MCP abilities.
if ( function_exists( 'wp_get_ability' ) ) {
	foreach ( array( 'export-values', 'site-history', 'overrides', 'list-undo', 'preview-undo', 'list-requests', 'decide-request', 'local-seo-preview' ) as $name ) { ok( (bool) wp_get_ability( 'brand-fleet/' . $name ), 'ability registered: ' . $name ); }
	$out = wp_get_ability( 'brand-fleet/export-values' )->execute( array( 'site_ids' => array( $a ) ) );
	ok( is_array( $out ) && str_starts_with( $out['csv'] ?? '', 'site_id,domain' ), 'export-values ability returns CSV' );
	$out = wp_get_ability( 'brand-fleet/local-seo-preview' )->execute( array( 'site_id' => $c ) );
	ok( 'Ottawa Dental' === ( $out['json_ld']['name'] ?? '' ), 'local-seo-preview ability returns JSON-LD' );
	$out = wp_get_ability( 'brand-fleet/overrides' )->execute( array() );
	ok( isset( $out['overrides']['location_name'] ), 'overrides ability returns counts' );
} else { ok( false, 'Abilities API missing' ); }
