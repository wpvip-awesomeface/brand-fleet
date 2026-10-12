<?php
/**
 * Integration test for fleet find and replace, embeds, and dropdown / yes-no / date variables.
 *
 * Run only on a disposable local multisite (localhost or a VIP dev-env *.lndo.site)
 * with Brand Fleet network-activated:
 *   wp eval-file tests/find-replace.php
 * It creates two sites and a few definitions, then removes them.
 */
use BrandFleet\Fleet;
use BrandFleet\Fleet_Embeds;
use BrandFleet\Fleet_Jobs;
use BrandFleet\Settings;
$host = (string) wp_parse_url( network_home_url(), PHP_URL_HOST );
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! is_multisite() || ! ( str_ends_with( $host, 'localhost' ) || str_ends_with( $host, '.lndo.site' ) ) ) {
	exit( 1 );
}
$GLOBALS['replace_fail'] = 0;
function ok( $cond, $msg ) {
	WP_CLI::log( ( $cond ? 'PASS ' : 'FAIL ' ) . $msg );
	if ( ! $cond ) { $GLOBALS['replace_fail']++; }
}
function run_until( string $phase ): array {
	for ( $n = 0; $n < 200; ++$n ) {
		$j = Fleet_Jobs::get();
		if ( $j['phase'] === $phase ) { return $j; }
		Fleet_Jobs::step( $j['id'], (int) $j['cursor'], 'ready' === $j['phase'] );
	}
	return Fleet_Jobs::get();
}
wp_set_current_user( get_user_by( 'login', current( get_super_admins() ) )->ID );
$schema = get_network_option( get_current_network_id(), Fleet::SCHEMA, array() );
$net = get_network(); $stamp = (string) time();
$sites = array( wpmu_create_blog( $net->domain, '/fr-a-' . $stamp . '/', 'FR A', get_current_user_id() ), wpmu_create_blog( $net->domain, '/fr-b-' . $stamp . '/', 'FR B', get_current_user_id() ) );
try {
	// Dropdown, yes / no and date definitions.
	Fleet::save_definition( 't_tier', array( 'label' => 'Tier', 'type' => 'select', 'options' => "Gold\nSilver", 'default' => 'Silver', 'scope' => 'location', 'access' => 'all', 'group' => 'Plans' ) );
	Fleet::save_definition( 't_delivery', array( 'label' => 'Delivery', 'type' => 'toggle', 'options' => "Delivers\nPickup only", 'default' => '1', 'scope' => 'location', 'access' => 'all' ) );
	Fleet::save_definition( 't_opened', array( 'label' => 'Opened', 'type' => 'date', 'default' => '2024-03-05', 'scope' => 'location', 'access' => 'all' ) );
	Fleet::save_definition( 't_ga', array( 'label' => 'GA ID', 'type' => 'text', 'default' => 'G-NETWORK', 'scope' => 'location', 'access' => 'all' ) );
	Fleet::save_definition( 't_embed', array( 'label' => 'Analytics', 'type' => 'embed', 'default' => '<script>gtag("config","{{t_ga}}");</script>', 'placement' => 'footer', 'targets' => array( $sites[0] ) ) );
	$defs = Fleet::definitions();
	ok( 'network' === $defs['t_embed']['scope'] && 'none' === $defs['t_embed']['access'], 'embeds are always network-locked' );
	ok( 'Plans' === $defs['t_tier']['group'] && array( 'Gold', 'Silver' ) === $defs['t_tier']['options'], 'dropdown keeps its options and group' );
	$rejected = false;
	try { Fleet::save_definition( 't_tier', array( 'type' => 'select', 'options' => "Gold\nSilver", 'default' => 'Bronze', 'scope' => 'location', 'access' => 'all' ) ); } catch ( \InvalidArgumentException $e ) { $rejected = true; }
	ok( $rejected, 'dropdown rejects a value that is not an option' );
	foreach ( $sites as $site ) { Fleet::save_profile( $site, array(), array( 'group_id' => 0, 'source_id' => 0, 'template_id' => 0 ) ); }
	Fleet::save_profile( $sites[0], array( 't_tier' => 'Gold', 't_delivery' => '0', 't_ga' => 'G-ABC123' ) );
	Fleet::save_profile( $sites[1], array( 't_ga' => '"); alert(1); ("' ) );

	switch_to_blog( $sites[0] );
	$tokens = Settings::tokens();
	ok( 'Gold' === $tokens['{{t_tier}}'] && 'Pickup only' === $tokens['{{t_delivery}}'], 'dropdown and yes / no tokens show their labels' );
	ok( 'March 5, 2024' === wp_date( 'F j, Y', strtotime( '2024-03-05 12:00:00 UTC' ) ) && str_contains( $tokens['{{t_opened}}'], '2024' ), 'date token is formatted with the site date format' );
	ok( ! isset( $tokens['{{t_embed}}'] ), 'embed code is never a content token' );
	ok( str_contains( Fleet_Embeds::markup( 'footer' ), 'gtag("config","G-ABC123")' ), 'embed prints on a targeted site with its value' );
	ok( ! str_contains( Fleet_Embeds::markup( 'head' ), 'embed: t_embed' ), 'embed only prints at its placement' );
	$page_a = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'About Acme Widgets', 'post_content' => '<!-- wp:paragraph --><p>Acme Widgets builds things. <a href="https://acme.example/acme widgets">Acme Widgets</a></p><!-- /wp:paragraph -->' ) );
	$page_skip = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Leave me', 'post_content' => '<p>acme widgets here</p>' ) );
	$page_late = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Edited later', 'post_content' => '<p>Acme Widgets later</p>' ) );
	restore_current_blog();
	switch_to_blog( $sites[1] );
	ok( ! str_contains( Fleet_Embeds::markup( 'footer' ), 'embed: t_embed' ), 'embed skips sites outside its targets' );
	$page_b = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Contact', 'post_content' => '<p>Call Acme Widgets ($1 off)</p>' ) );
	restore_current_blog();

	$j = Fleet_Jobs::start_replace( $sites, array( 'find' => 'Acme Widgets', 'variable' => 'business_name', 'types' => array( 'page' ) ) );
	$j = run_until( 'ready' );
	ok( 2 === count( $j['hits'] ), 'preview finds matches on both sites' );
	$rows = Fleet_Jobs::hit_rows( $j, 0 );
	$first = $rows[0]['matches'] ?? array();
	ok( 3 === count( $first ), 'case-insensitive search finds three pages on site A' );
	ok( 'Acme Widgets' === ( $first[0]['snippet'][1] ?? '' ), 'snippet highlights the match' );
	Fleet_Jobs::exclude( $j['id'], 0, $page_skip, true );
	switch_to_blog( $sites[0] );
	wp_update_post( array( 'ID' => $page_late, 'post_content' => '<p>Acme Widgets edited after preview</p>' ) );
	restore_current_blog();
	$j = Fleet_Jobs::get();
	Fleet_Jobs::step( $j['id'], (int) $j['cursor'], true );
	$j = run_until( 'complete' );
	switch_to_blog( $sites[0] );
	$a = get_post( $page_a );
	ok( 'About {{business_name}}' === $a->post_title, 'title text is replaced with the variable' );
	ok( str_contains( $a->post_content, '<p>{{business_name}} builds things.' ) && str_contains( $a->post_content, '>{{business_name}}</a>' ), 'visible text is replaced' );
	ok( str_contains( $a->post_content, 'href="https://acme.example/acme widgets"' ), 'links are untouched unless markup is included' );
	ok( (bool) wp_get_post_revisions( $page_a ), 'a revision is kept for rollback' );
	ok( str_contains( get_post( $page_skip )->post_content, 'acme widgets here' ), 'left-out page is untouched' );
	ok( str_contains( get_post( $page_late )->post_content, 'edited after preview' ), 'page edited after preview is skipped' );
	restore_current_blog();
	switch_to_blog( $sites[1] );
	ok( '<p>Call {{business_name}} ($1 off)</p>' === get_post( $page_b )->post_content, 'drafts on other sites are replaced; $ in text is literal' );
	restore_current_blog();
	$results = array_column( Fleet_Jobs::hit_rows( $j, 0 )[0]['matches'], 'result' );
	sort( $results );
	ok( array( 'changed since preview', 'left out', 'replaced' ) === $results, 'results report replaced, left out and changed pages' );

	$j = Fleet_Jobs::start_replace( array( $sites[1] ), array( 'find' => '{{business_name}}', 'with' => 'Acme', 'markup' => true, 'case' => true ) );
	ok( 'replace' === $j['kind'], 'markup replacement batch starts' );
	Fleet_Jobs::cancel( $j['id'] );
	$bad = false;
	try { Fleet_Jobs::start_replace( $sites, array( 'find' => 'x"y', 'with' => 'a"b', 'markup' => true ) ); } catch ( \InvalidArgumentException $e ) { $bad = true; }
	ok( $bad, 'markup replacements cannot inject quotes' );
} finally {
	if ( ms_is_switched() ) { restore_current_blog(); }
	foreach ( $sites as $site ) { wp_delete_site( $site ); }
	update_network_option( get_current_network_id(), Fleet::SCHEMA, $schema );
}
$fail = $GLOBALS['replace_fail'];
WP_CLI::log( $fail ? "$fail failed" : 'All passed' );
exit( $fail ? 1 : 0 );
