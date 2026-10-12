<?php
/**
 * Integration test for typed variables: validation, attribute-aware swaps, titles,
 * custom fields and block bindings.
 *
 * Run only on a disposable local multisite (localhost or a VIP dev-env *.lndo.site)
 * with Brand Fleet network-activated:
 *   wp eval-file tests/typed-variables.php
 * It creates one site and four definitions, then removes them.
 */
use BrandFleet\Content;
use BrandFleet\Fleet;
$host = (string) wp_parse_url( network_home_url(), PHP_URL_HOST );
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! is_multisite() || ! ( str_ends_with( $host, 'localhost' ) || str_ends_with( $host, '.lndo.site' ) ) ) {
	exit( 1 );
}
$GLOBALS['typed_fail'] = 0;
function ok( $cond, $msg ) {
	WP_CLI::log( ( $cond ? 'PASS ' : 'FAIL ' ) . $msg );
	if ( ! $cond ) { $GLOBALS['typed_fail']++; }
}
function throws( callable $fn ): bool {
	try { $fn(); return false; } catch ( \InvalidArgumentException $e ) { return true; }
}
wp_set_current_user( get_user_by( 'login', current( get_super_admins() ) )->ID );

ok( 'https://example.com/a.png' === Fleet::clean( 'https://example.com/a.png', array( 'type' => 'image' ) ), 'image accepts an https URL' );
ok( throws( static fn() => Fleet::clean( 'javascript:alert(1)', array( 'type' => 'image' ) ) ), 'image rejects javascript: URLs' );
ok( '+1 (555) 010-2000 x12' === Fleet::clean( '+1 (555) 010-2000 x12', array( 'type' => 'phone' ) ), 'phone accepts formatted numbers' );
ok( throws( static fn() => Fleet::clean( '<script>', array( 'type' => 'phone' ) ) ), 'phone rejects markup' );

$schema = get_network_option( get_current_network_id(), Fleet::SCHEMA, array() );
$meta_keys = Fleet::meta_keys();
$defs = array(
	't_hero'  => array( 'type' => 'image', 'default' => 'https://example.com/default.png' ),
	't_brand' => array( 'type' => 'color', 'default' => '#112233' ),
	't_phone' => array( 'type' => 'phone', 'default' => '' ),
	't_name'  => array( 'type' => 'text', 'default' => '' ),
);
foreach ( $defs as $key => $d ) { Fleet::save_definition( $key, $d + array( 'label' => $key, 'scope' => 'location', 'access' => 'all' ) ); }
$net = get_network();
$site = wpmu_create_blog( $net->domain, '/typed-' . time() . '/', 'Typed', get_current_user_id() );
try {
	Fleet::save_profile( $site, array( 't_hero' => 'https://example.com/hero.png', 't_phone' => '+1 (555) 010-2000', 't_name' => 'Joe\'s "Best" <b>Bakery</b>' ), array( 'group_id' => 0, 'source_id' => 0, 'template_id' => 0 ) );
	Fleet::save_meta_keys( 't_subtitle' );
	switch_to_blog( $site );
	$content = new Content();
	$html = $content->swap_tokens( '<p>Call {{t_phone}}</p><img src="{{t_hero}}" alt="{{t_name}} logo"><a href="tel:{{t_phone}}" onclick="go(\'{{t_name}}\')">Call</a><a href="{{t_name}}">Bad</a><div class="x-{{t_name}}" style="color:{{t_brand}};background:url({{t_hero}})"></div>' );
	ok( str_contains( $html, '<p>Call +1 (555) 010-2000</p>' ), 'text node swaps the phone number' );
	ok( str_contains( $html, 'src="https://example.com/hero.png"' ), 'image token resolves in src' );
	ok( str_contains( $html, 'alt="Joe&apos;s &quot;Best&quot; Bakery logo"' ), 'text token is escaped inside alt' );
	ok( str_contains( $html, 'href="tel:+1%20(555)%20010-2000"' ), 'phone token resolves in a tel: link' );
	ok( str_contains( $html, 'onclick="go(\'{{t_name}}\')"' ), 'event handler attributes are never swapped' );
	ok( str_contains( $html, 'href="{{t_name}}"' ), 'text tokens never become links' );
	ok( str_contains( $html, 'class="x-{{t_name}}"' ), 'text tokens stay out of class names' );
	ok( str_contains( $html, 'color:#112233' ) && str_contains( $html, 'url(https://example.com/hero.png)' ), 'color and image tokens resolve inside style' );
	ok( 'Visit Joe&#039;s &quot;Best&quot; Bakery' === $content->swap_text( 'Visit {{t_name}}' ), 'titles and SEO text swap with escaping' );
	$meta_post = wp_insert_post( array( 'post_title' => 'Meta', 'post_status' => 'publish', 'meta_input' => array( 't_subtitle' => 'Color {{t_brand}}', 'other' => 'Color {{t_brand}}' ) ) );
	ok( 'Color #112233' === get_post_meta( $meta_post, 't_subtitle', true ), 'chosen custom field swaps tokens' );
	ok( 'Color {{t_brand}}' === get_post_meta( $meta_post, 'other', true ), 'other custom fields are untouched' );
	ok( '' === get_post_meta( $meta_post, 'missing', true ), 'missing custom fields still return empty' );
	$bound = do_blocks( '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"brand-fleet/variable","args":{"key":"t_name"}}}}} --><p>Placeholder</p><!-- /wp:paragraph -->' );
	ok( str_contains( $bound, 'Bakery' ) && ! str_contains( $bound, 'Placeholder' ), 'block bindings source renders the site value' );
	restore_current_blog();
} finally {
	if ( ms_is_switched() ) { restore_current_blog(); }
	wp_delete_site( $site );
	update_network_option( get_current_network_id(), Fleet::SCHEMA, $schema );
	update_network_option( get_current_network_id(), Fleet::META_KEYS, $meta_keys );
}
$fail = $GLOBALS['typed_fail'];
WP_CLI::log( $fail ? "$fail failed" : 'All passed' );
exit( $fail ? 1 : 0 );
