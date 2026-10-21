<?php
/**
 * Integration test for segments, segment defaults, regional shared content, showcases,
 * fleet capabilities, imports, the token check, find & replace in menus and SEO fields,
 * cache invalidation and per-request memoization.
 *
 * Run only on a disposable local multisite (localhost or a VIP dev-env *.lndo.site)
 * with Brand Fleet network-activated:
 *   wp eval-file tests/fleet-p0.php
 * It creates sites, users, posts and definitions and leaves them; reset the network after.
 */
use BrandFleet\Fleet;
use BrandFleet\Fleet_Cache;
use BrandFleet\Fleet_Embeds;
use BrandFleet\Fleet_Import;
use BrandFleet\Fleet_Jobs;
use BrandFleet\Fleet_Segments;
use BrandFleet\Plugin;
use BrandFleet\Settings;
$host = (string) wp_parse_url( network_home_url(), PHP_URL_HOST );
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! is_multisite() || ! ( str_ends_with( $host, 'localhost' ) || str_ends_with( $host, '.lndo.site' ) ) ) {
	exit( 1 );
}
// Stand-in for the VIP purge API, so the purge queue runs locally.
if ( ! function_exists( 'wpvip_purge_edge_cache_for_post' ) ) {
	function wpvip_purge_edge_cache_for_post( $post ) { $GLOBALS['p0_purged'][ get_current_blog_id() ][] = (int) $post; }
}
$GLOBALS['p0_fail'] = 0;
function ok( $cond, $msg ) {
	WP_CLI::log( ( $cond ? 'PASS ' : 'FAIL ' ) . $msg );
	if ( ! $cond ) { $GLOBALS['p0_fail']++; }
}
function throws( callable $fn ): bool {
	try { $fn(); return false; } catch ( \InvalidArgumentException | \RuntimeException $e ) { return true; }
}
/** Step a batch until it stops moving; confirm applies a ready preview. */
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
function page( int $site, string $slug, string $content, string $type = 'page', int $parent = 0 ): int {
	switch_to_blog( $site );
	$id = wp_insert_post( array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => $slug, 'post_name' => $slug, 'post_parent' => $parent, 'post_content' => $content ) );
	restore_current_blog();
	return (int) $id;
}
$admin = get_user_by( 'login', current( get_super_admins() ) )->ID;
wp_set_current_user( $admin );
$content = Plugin::get_instance()->content;

// --- Segments and segment defaults.
def( 'province', array( 'type' => 'select', 'options' => "ON\nQC" ) );
def( 'language', array( 'type' => 'select', 'options' => "en\nfr" ) );
$a = site( 'qc-fr' ); $b = site( 'on-en' ); $c = site( 'qc-en' );
Fleet::save_profile( $a, array( 'province' => 'QC', 'language' => 'fr' ), array() );
Fleet::save_profile( $b, array( 'province' => 'ON', 'language' => 'en' ), array() );
Fleet::save_profile( $c, array( 'province' => 'QC', 'language' => 'en' ), array() );
Fleet_Segments::save( array(
	array( 'slug' => 'quebec', 'label' => 'Quebec', 'rules' => array( 'province' => array( 'QC' ) ) ),
	array( 'slug' => 'french', 'label' => 'French', 'rules' => array( 'language' => array( 'fr' ) ) ),
) );
ok( Fleet_Segments::matches( $a, 'quebec' ) && Fleet_Segments::matches( $c, 'quebec' ) && ! Fleet_Segments::matches( $b, 'quebec' ), 'segment membership follows the rule' );
$scanned = Fleet_Segments::sites( 'quebec' ); sort( $scanned );
ok( Fleet_Segments::pending() && array( $a, $c ) === $scanned, 'targeting scans profiles while the index rebuilds' );
Fleet_Segments::rebuild( 10 );
$indexed = Fleet_Segments::sites( 'quebec' ); sort( $indexed );
ok( ! Fleet_Segments::pending() && array( $a, $c ) === $indexed, 'targeting reads the index once rebuilt' );
ok( array( $a ) === Fleet_Segments::sites( 'french' ), 'second segment targets only French sites' );
Fleet::save_profile( $c, array( 'province' => 'ON' ) );
ok( ! in_array( $c, Fleet_Segments::sites( 'quebec' ), true ), 'saving a site refreshes its index entry' );
Fleet::save_profile( $c, array( 'province' => 'QC' ) );

def( 'cta', array( 'type' => 'text', 'default' => 'Book now', 'scope' => 'network', 'segment_defaults' => array( 'french' => 'Réservez' ) ) );
ok( 'Réservez' === Fleet::resolved( $a, 'cta' )['value'] && 'segment french' === Fleet::resolved( $a, 'cta' )['source'], 'network variable uses its segment default' );
ok( 'Book now' === Fleet::resolved( $b, 'cta' )['value'], 'other sites keep the network default' );
def( 'notice', array( 'type' => 'text', 'default' => 'Hello', 'segment_defaults' => array( 'quebec' => 'Bonjour QC', 'french' => 'Bonjour FR' ) ) );
ok( 'Bonjour QC' === Fleet::resolved( $a, 'notice' )['value'], 'first segment in order wins when a site is in several' );
$h = Fleet::hash( Fleet_Segments::all() );
Fleet_Segments::save( array( Fleet_Segments::all()['french'] + array( 'slug' => 'french' ), Fleet_Segments::all()['quebec'] + array( 'slug' => 'quebec' ) ), $h );
ok( 'Bonjour FR' === Fleet::resolved( $a, 'notice' )['value'], 'reordering segments changes which default wins' );
Fleet::save_profile( $a, array( 'notice' => 'Our own' ) );
ok( 'Our own' === Fleet::resolved( $a, 'notice' )['value'], 'a site value beats segment defaults' );
$parent = site( 'parent-data' );
Fleet::save_profile( $parent, array( 'notice' => 'From parent', 'province' => 'ON' ), array() );
Fleet::save_profile( $c, array(), array( 'source_id' => $parent ) );
ok( 'From parent' === Fleet::resolved( $c, 'notice' )['value'], 'main data site beats segment defaults' );
ok( throws( static fn() => def( 'province', array( 'type' => 'select', 'options' => "ON\nQC", 'segment_defaults' => array( 'quebec' => 'QC' ) ) ) ), 'a segment cannot give a default to its own rule variable' );
ok( throws( static fn() => Fleet_Segments::save( array( Fleet_Segments::all()['french'] + array( 'slug' => 'french' ) ) ) ), 'removing a segment that still gives defaults is refused' );
ok( throws( static fn() => Fleet_Segments::save( array( array( 'slug' => 'bad', 'rules' => array( 'cta' => array( 'x' ) ) ) ) ) ), 'segments use dropdown or yes / no variables only' );

// --- Memoized tokens stay correct after writes in the same request.
switch_to_blog( $b );
$first = Settings::tokens()['{{notice}}'];
restore_current_blog();
Fleet::save_profile( $b, array( 'notice' => 'Changed now' ) );
switch_to_blog( $b );
ok( 'Hello' === $first && 'Changed now' === Settings::tokens()['{{notice}}'], 'token map is cached per request and cleared on write' );
restore_current_blog();

// --- Embeds targeted by segment.
def( 'qc_embed', array( 'type' => 'embed', 'default' => '<script>qc()</script>', 'placement' => 'footer', 'segment' => 'quebec' ) );
switch_to_blog( $a ); $in_a = Fleet_Embeds::markup( 'footer' ); restore_current_blog();
switch_to_blog( $b ); $in_b = Fleet_Embeds::markup( 'footer' ); restore_current_blog();
ok( str_contains( $in_a, 'qc()' ) && ! str_contains( $in_b, 'qc()' ), 'embed prints only on sites in its segment' );

// --- Regional shared content, showcases and content masters.
$m = site( 'master' );
page( $m, 'privacy', '<!-- wp:paragraph --><p>Privacy generic for {{province}}</p><!-- /wp:paragraph -->' );
page( $m, 'privacy-qc', '<!-- wp:paragraph --><p>Privacy Quebec</p><!-- /wp:paragraph -->' );
page( $m, 'faq-set', '<!-- wp:paragraph --><p>FAQ showcase {{cta}}</p><!-- /wp:paragraph -->', 'wp_block' );
foreach ( array( $a, $b ) as $site ) { Fleet::save_profile( $site, array(), array( 'source_id' => $m ) ); }
Fleet::save_profile( $c, array(), array( 'source_id' => $m ) );
switch_to_blog( $a ); $qc = $content->render_shared( 'privacy-{{province}}' ); $faq = $content->render_shared( 'faq-set', 'pattern' ); restore_current_blog();
switch_to_blog( $b ); $on = $content->render_shared( 'privacy-{{province}}' ); restore_current_blog();
ok( str_contains( $qc, 'Privacy Quebec' ), 'regional slug picks the province version' );
ok( str_contains( $on, 'Privacy generic for ON' ), 'missing regional version falls back to the generic page, with local tokens' );
ok( str_contains( $faq, 'FAQ showcase Réservez' ), 'synced pattern renders as a showcase with local values' );

$fr_master = site( 'master-fr' );
page( $fr_master, 'privacy', '<!-- wp:paragraph --><p>Confidentialité</p><!-- /wp:paragraph -->' );
def( 'content_master', array( 'type' => 'number', 'scope' => 'network', 'default' => '', 'segment_defaults' => array( 'french' => (string) $fr_master ) ) );
switch_to_blog( $a ); $fr = $content->render_shared( 'privacy' ); restore_current_blog();
switch_to_blog( $b ); $en = $content->render_shared( 'privacy' ); restore_current_blog();
ok( str_contains( $fr, 'Confidentialité' ) && str_contains( $en, 'Privacy generic' ), 'content_master picks the French master for French sites' );

// --- Master edits clear the shared copy and queue only consumer pages.
Fleet_Segments::rebuild( 10 );
$consumer = page( $b, 'uses-shared', '<!-- wp:brand-fleet/shared-content {"slug":"privacy"} /-->' );
update_network_option( get_current_network_id(), Fleet_Cache::QUEUE, array() );
switch_to_blog( $m );
$privacy = get_page_by_path( 'privacy' );
wp_update_post( array( 'ID' => $privacy->ID, 'post_content' => '<!-- wp:paragraph --><p>Privacy updated</p><!-- /wp:paragraph -->' ) );
restore_current_blog();
switch_to_blog( $b ); $updated = $content->render_shared( 'privacy' ); restore_current_blog();
ok( str_contains( $updated, 'Privacy updated' ), 'editing the master page clears the cached copy at once' );
$queue = (array) get_network_option( get_current_network_id(), Fleet_Cache::QUEUE, array() );
ok( isset( $queue['consumers'][ $b ] ) && ! isset( $queue['consumers'][ $fr_master ] ), 'locations reading from the master are queued for a purge' );
$GLOBALS['p0_purged'] = array();
Fleet_Cache::drain( 10 );
ok( array( $consumer ) === ( $GLOBALS['p0_purged'][ $b ] ?? array() ), 'purge touches only pages that show shared content' );

// --- Fleet capabilities for main-site roles.
$editor = wp_insert_user( array( 'user_login' => 'p0editor', 'user_pass' => wp_generate_password(), 'role' => 'editor', 'user_email' => 'p0editor@example.com' ) );
switch_to_blog( get_main_site_id() ); get_role( 'editor' )->add_cap( 'brand_fleet_bulk' ); restore_current_blog();
wp_set_current_user( $editor );
ok( Fleet::can( 'bulk' ) && ! Fleet::can( 'manage' ) && ! Fleet::network_admin(), 'a main-site role can be given bulk access only' );
$job = Fleet_Jobs::start( array( $b ), array( 'notice' => 'By editor' ) );
ok( 'preview' === $job['phase'], 'that role can start a bulk update' );
Fleet_Jobs::cancel( $job['id'] );
ok( throws( static fn() => def( 'x_editor', array( 'type' => 'text' ) ) ), 'that role cannot change variable definitions' );
ok( BrandFleet\Fleet_Abilities::allowed( 'preview-bulk' ) && ! BrandFleet\Fleet_Abilities::allowed( 'save-definition' ) && ! BrandFleet\Fleet_Abilities::allowed( 'clone-site' ), 'abilities follow the same capabilities' );
switch_to_blog( get_main_site_id() ); get_role( 'editor' )->remove_cap( 'brand_fleet_bulk' ); restore_current_blog();
wp_set_current_user( 0 ); wp_set_current_user( $editor );
ok( ! Fleet::can_any(), 'removing the capability removes access' );
wp_set_current_user( $admin );

// --- Imported values and spreadsheet import.
def( 'hours', array( 'type' => 'text', 'scope' => 'imported' ) );
def( 'practice_code', array( 'type' => 'text', 'identifier' => true ) );
Fleet::save_profile( $a, array( 'practice_code' => 'P-100' ) );
Fleet::save_profile( $b, array( 'practice_code' => 'P-200' ) );
ok( throws( static fn() => Fleet::save_profile( $a, array( 'hours' => '9-5' ) ) ), 'imported values cannot be edited by hand' );
$csv = "practice_code,hours,phone_sales,province,unknown_col\nP-100,9-5,555-0100,,x\nP-200,,,ZZ,\nP-999,8-4,,,\n";
$j = run_job( Fleet_Import::start( $csv, 'practice_code', 'test' ) );
$problems = implode( ' | ', $j['import']['problems'] );
ok( 'ready' === $j['phase'] && str_contains( $problems, 'unknown_col' ) && str_contains( $problems, 'P-999' ) && str_contains( $problems, 'province' ), 'import preview lists unknown columns, unmatched rows and bad values' );
ok( '' === Fleet::resolved( $a, 'hours' )['value'], 'preview writes nothing' );
$j = run_job( $j, true );
ok( 'complete' === $j['phase'] && '9-5' === Fleet::resolved( $a, 'hours' )['value'] && '555-0100' === Fleet::resolved( $a, 'phone_sales' )['value'], 'applied import writes each site its own values' );
ok( '' === Fleet::resolved( $b, 'hours' )['value'], 'empty cells leave values unchanged' );
$auto = Fleet_Jobs::start_import( array( $b => array( 'hours' => '10-6' ) ), array( 'label' => 'auto' ), true );
$auto = run_job( $auto );
ok( 'complete' === $auto['phase'] && '10-6' === Fleet::resolved( $b, 'hours' )['value'], 'scheduled import applies without review' );
$by_domain = run_job( Fleet_Import::start( "domain,hours\n" . $host . "/qc-en/,7-3\n", 'domain', 'domain' ), true );
ok( '7-3' === Fleet::resolved( $c, 'hours' )['value'], 'import can match rows by site address' );

// --- Token check.
page( $b, 'tokens', '<!-- wp:paragraph --><p>{{nope_key}} and {{hours}} and {{cta}}</p><!-- /wp:paragraph -->' );
Fleet::import_write( static fn() => Fleet::save_profile( $b, array( 'hours' => '' ) ) );
$report = run_job( Fleet_Jobs::start_report( array( $a, $b ), array() ) );
$rows = Fleet_Jobs::hit_rows( $report, 0, 25 );
$row = reset( $rows );
ok( 'complete' === $report['phase'] && 1 === count( $rows ) && (int) $row['site'] === $b, 'token check flags only the site with problems' );
ok( array( 'nope_key' ) === $row['matches'][0]['unknown'] && array( 'hours' ) === $row['matches'][0]['empty'], 'token check separates unknown and empty tokens' );

// --- Find & replace in menus and SEO fields, and repeat passes.
switch_to_blog( $b );
$nav = wp_insert_post( array( 'post_type' => 'wp_navigation', 'post_status' => 'publish', 'post_title' => 'Main', 'post_content' => '<!-- wp:navigation-link {"label":"Call 555-0000","url":"tel:5550000"} /-->' ) );
$seo = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'SEO page', 'post_content' => '<p>Nothing here</p>' ) );
update_post_meta( $seo, '_yoast_wpseo_title', 'Call 555-0000 today' );
restore_current_blog();
$r = run_job( Fleet_Jobs::start_replace( array( $b ), array( 'find' => '555-0000', 'with' => '555-1111', 'markup' => true, 'meta' => true ) ) );
$r = run_job( $r, true );
switch_to_blog( $b );
ok( str_contains( get_post( $nav )->post_content, '555-1111' ), 'find & replace reaches menus' );
ok( 'Call 555-1111 today' === get_post_meta( $seo, '_yoast_wpseo_title', true ), 'find & replace reaches SEO titles when asked' );
for ( $i = 0; $i < 22; ++$i ) { wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Old brand ' . $i, 'post_content' => '<p>Old Brand Name</p>' ) ); }
restore_current_blog();
$r = run_job( Fleet_Jobs::start_replace( array( $b ), array( 'find' => 'Old Brand Name', 'with' => 'New Brand Name' ) ) );
ok( array( $b ) === Fleet_Jobs::view( $r )['more_sites'], 'sites with more matches than one pass are listed for a repeat run' );

$fail = $GLOBALS['p0_fail'];
WP_CLI::log( $fail ? "$fail failed" : 'All passed' );
exit( $fail ? 1 : 0 );
