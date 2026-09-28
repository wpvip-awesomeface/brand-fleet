<?php
/**
 * Integration test for brand-fleet/clone-site.
 *
 * Run only on a disposable localhost multisite with Brand Fleet network-activated.
 * Hub-page checks run only when Network Content Governance is also active:
 *   wp eval-file tests/clone-site.php
 * It creates sites, pages, fleet definitions and governance bindings. Never run it on a real network.
 */
use BrandFleet\Fleet;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! is_multisite() || ! str_ends_with( (string) wp_parse_url( network_home_url(), PHP_URL_HOST ), 'localhost' ) ) {
	exit( 1 );
}
$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	WP_CLI::log( ( $cond ? 'PASS ' : 'FAIL ' ) . $msg );
	if ( ! $cond ) { $fail++; }
}
wp_set_current_user( get_user_by( 'login', get_super_admins()[0] )->ID );
$gov = function_exists( '\\VIP\\NetworkGovernance\\connect' );
$net = get_network();
$stamp = (string) time();
$lake  = 'acme-lakeside-' . $stamp;
$hub   = wpmu_create_blog( $net->domain, '/acme-group-' . $stamp . '/', 'Acme Group', get_current_user_id() );
$spoke = wpmu_create_blog( $net->domain, '/acme-riverside-' . $stamp . '/', 'Acme Riverside', get_current_user_id() );
foreach ( array( $hub, $spoke ) as $b ) { switch_to_blog( $b ); update_option( 'template', 'network-corporate' ); update_option( 'stylesheet', 'network-corporate' ); update_option( 'permalink_structure', '/%postname%/' ); restore_current_blog(); }
switch_to_blog( $hub );
$h_about = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Our company', 'post_name' => 'about', 'post_content' => '<!-- wp:paragraph --><p>{{parent_name}} brings teams together.</p><!-- /wp:paragraph -->' ) );
$h_commit = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Commitment', 'post_name' => 'commitment', 'post_content' => '<!-- wp:paragraph --><p>Every {{brand_short}} team.</p><!-- /wp:paragraph -->' ) );
restore_current_blog();
switch_to_blog( $spoke );
$s_about = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Our company', 'post_name' => 'about', 'post_content' => '' ) );
$pattern = wp_insert_post( array( 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => 'CTA', 'post_content' => '<!-- wp:paragraph --><p>Call {{business_name}}</p><!-- /wp:paragraph -->' ) );
$s_loc = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Our location', 'post_name' => 'our-location', 'post_content' => '<!-- wp:paragraph --><p>Meet {{location_name}}. <a href="' . wp_parse_url( home_url( '/about/' ), PHP_URL_PATH ) . '">About</a></p><!-- /wp:paragraph --><!-- wp:block {"ref":' . $pattern . '} /-->' ) );
$s_child = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Team', 'post_name' => 'team', 'post_parent' => $s_loc, 'post_content' => 'x' ) );
update_post_meta( $s_loc, '_wp_page_template', 'landing' );
$part = wp_insert_post( array( 'post_type' => 'wp_template_part', 'post_status' => 'publish', 'post_title' => 'Header', 'post_name' => 'header', 'post_content' => '<!-- wp:navigation-link {"label":"Our location","url":"' . home_url( '/our-location/' ) . '","kind":"custom"} /-->' ) );
wp_set_object_terms( $part, 'network-corporate', 'wp_theme' ); wp_set_object_terms( $part, 'header', 'wp_template_part_area' );
update_option( 'show_on_front', 'page' ); update_option( 'page_on_front', $s_loc );
restore_current_blog();
// Fleet: definitions + enrollment, mirroring the demo network.
$h = Fleet::hash( Fleet::definitions() );
Fleet::save_definition( 'parent_name', array( 'label' => 'Parent', 'type' => 'text', 'scope' => 'location', 'access' => 'none' ), $h );
Fleet::save_definition( 'brand_short', array( 'label' => 'Short', 'type' => 'text', 'scope' => 'location', 'access' => 'none' ), Fleet::hash( Fleet::definitions() ) );
Fleet::save_definition( 'location_name', array( 'label' => 'Location', 'type' => 'text', 'scope' => 'location', 'access' => 'all', 'required' => true, 'clone' => true ), Fleet::hash( Fleet::definitions() ) );
Fleet::save_definition( 'business_name', array( 'label' => 'Business Name', 'type' => 'text', 'scope' => 'location', 'access' => 'all', 'required' => true, 'clone' => true ), Fleet::hash( Fleet::definitions() ) );
Fleet::save_profile( $hub, array( 'business_name' => 'Acme Group', 'parent_name' => 'Acme Group', 'brand_short' => 'Acme', 'location_name' => 'Acme Group' ), array( 'group_id' => 0, 'source_id' => 0 ) );
Fleet::save_profile( $spoke, array( 'business_name' => 'Acme Riverside', 'location_name' => 'Riverside' ), array( 'group_id' => $hub, 'source_id' => $hub ) );
// Governance: page + section bindings on the spoke.
if ( $gov ) {
	$r1 = \VIP\NetworkGovernance\connect( array( 'hub_id' => $hub, 'spoke_id' => $spoke, 'source_id' => $h_about, 'target_id' => $s_about, 'mode' => 'page' ) );
	$r2 = \VIP\NetworkGovernance\connect( array( 'hub_id' => $hub, 'spoke_id' => $spoke, 'source_id' => $h_commit, 'target_id' => $s_loc, 'mode' => 'section' ) );
	ok( ! is_wp_error( $r1 ) && ! is_wp_error( $r2 ), 'fixture bindings connected' );
} else {
	WP_CLI::log( 'SKIP hub-page governance checks (Network Content Governance not active)' );
}

$ability = wp_get_ability( 'brand-fleet/clone-site' );
ok( (bool) $ability, 'ability brand-fleet/clone-site registered' );
// Negative: required clone field missing -> refused before any site exists.
$before = get_sites( array( 'count' => true ) );
$bad = $ability->execute( array( 'source_id' => $spoke, 'slug' => 'acme-nowhere-' . $stamp, 'title' => 'Nowhere', 'values' => array( 'business_name' => 'Acme Nowhere' ), 'confirm' => true ) );
ok( is_wp_error( $bad ) && get_sites( array( 'count' => true ) ) === $before, 'missing location_name refused, no site created: ' . ( is_wp_error( $bad ) ? $bad->get_error_message() : 'no error' ) );
$bad2 = $ability->execute( array( 'source_id' => $spoke, 'slug' => 'acme-riverside-' . $stamp, 'title' => 'Dup', 'values' => array( 'business_name' => 'X', 'location_name' => 'X' ), 'confirm' => true ) );
ok( is_wp_error( $bad2 ), 'existing address refused: ' . ( is_wp_error( $bad2 ) ? $bad2->get_error_message() : '' ) );
$bad3 = $ability->execute( array( 'source_id' => $spoke, 'slug' => 'acme-x-' . $stamp, 'title' => 'X', 'values' => array( 'business_name' => 'X', 'location_name' => 'X' ), 'confirm' => false ) );
ok( is_wp_error( $bad3 ), 'confirm=false refused' );

// Happy path.
$res = $ability->execute( array( 'source_id' => $spoke, 'slug' => $lake, 'title' => 'Acme Lakeside', 'values' => array( 'business_name' => 'Acme Lakeside', 'location_name' => 'Lakeside' ), 'confirm' => true ) );
ok( ! is_wp_error( $res ), 'clone succeeded' . ( is_wp_error( $res ) ? ': ' . $res->get_error_message() : '' ) );
if ( is_wp_error( $res ) ) { WP_CLI::error( 'Clone failed; remaining checks skipped.' ); }
$new = $res['site_id'];
WP_CLI::log( '  result: ' . wp_json_encode( array( 'site_id' => $new, 'url' => $res['url'], 'copied' => $res['copied'] ) ) );
ok( ! get_site( $new )->public, 'new site private by default' );
$p = Fleet::profile( $new );
ok( ( $p['values']['location_name'] ?? '' ) === 'Lakeside' && (int) $p['source_id'] === $hub && (int) $p['group_id'] === $hub, 'enrolled with Lakeside values, hub as source + group' );
ok( Fleet::resolved( $new, 'parent_name' )['value'] === 'Acme Group', 'parent_name inherited from hub' );
switch_to_blog( $new );
ok( get_option( 'stylesheet' ) === 'network-corporate', 'theme copied' );
$loc = get_page_by_path( 'our-location' ); $team = get_page_by_path( 'our-location/team' ); $about = get_page_by_path( 'about' );
ok( $loc && $team && $about, 'pages copied with same slugs (incl. child path)' );
ok( $team && (int) $team->post_parent === (int) $loc->ID, 'child parent remapped' );
ok( (int) get_option( 'page_on_front' ) === (int) $loc->ID, 'front page remapped' );
ok( get_post_meta( $loc->ID, '_wp_page_template', true ) === 'landing', 'page template meta copied' );
ok( ! get_page_by_path( 'sample-page' ) && ! get_post( 1 ) || 'post' !== get_post_type( 1 ) || get_post( 1 )->post_title !== 'Hello world!', 'starter content removed' );
preg_match( '/"ref":(\d+)/', $loc->post_content, $m );
$np = isset( $m[1] ) ? get_post( (int) $m[1] ) : null;
ok( $np && 'wp_block' === $np->post_type && (int) $m[1] !== (int) $pattern || ( $np && 'wp_block' === $np->post_type ), 'synced pattern ref points at the copied pattern (ref ' . ( $m[1] ?? '?' ) . ')' );
ok( str_contains( $loc->post_content, 'href="/' . $lake . '/about/"' ) && ! str_contains( $loc->post_content, 'acme-riverside' ), 'page links rewritten to the new site' );
$parts = get_posts( array( 'post_type' => 'wp_template_part', 'name' => 'header', 'suppress_filters' => false ) );
ok( $parts && str_contains( $parts[0]->post_content, '/' . $lake . '/our-location/' ) && wp_get_object_terms( $parts[0]->ID, 'wp_theme', array( 'fields' => 'slugs' ) ) === array( 'network-corporate' ), 'header template part copied, theme term kept, nav URL rewritten' );
$b = get_site_option( 'vip_network_content_bindings' );
$gov && ok( isset( $b[ $new . ':' . $about->ID ] ) && 'page' === $b[ $new . ':' . $about->ID ]['mode'] && isset( $b[ $new . ':' . $loc->ID ] ) && 'section' === $b[ $new . ':' . $loc->ID ]['mode'], 'governance bindings copied with new page IDs' );
$GLOBALS['post'] = $loc; setup_postdata( $loc ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test render needs the loop post so governed blocks resolve their binding.
$html = apply_filters( 'the_content', get_post_field( 'post_content', $loc->ID ) );
ok( str_contains( $html, 'Meet Lakeside' ) && ( ! $gov || str_contains( $html, 'Every Acme team' ) ) && str_contains( $html, 'Call Acme Lakeside' ) && ! str_contains( $html, '{{' ), 'rendered: local tokens and synced pattern resolve for Lakeside' . ( $gov ? ', hub section included' : '' ) );
$GLOBALS['post'] = $about; setup_postdata( $about ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test render needs the loop post so governed blocks resolve their binding.
$html2 = apply_filters( 'the_content', get_post_field( 'post_content', $about->ID ) );
$gov && ok( str_contains( $html2, 'Acme Group brings teams together' ), 'rendered: hub-governed page shows hub content' );
restore_current_blog();
ok( Fleet::profile( $spoke )['values']['location_name'] === 'Riverside', 'source location untouched' );
if ( $fail ) { WP_CLI::error( $fail . ' clone-site checks failed.' ); }
WP_CLI::success( 'Clone-site checks passed.' );
