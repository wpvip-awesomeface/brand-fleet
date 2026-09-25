<?php
/** Accessible site selection with bounded network lookups. */
namespace BrandFleet;

defined( 'ABSPATH' ) || exit;
class Site_Picker {
	public static function label( int $id ): string {
		$site = get_site( $id );
		if ( ! $site ) { return 'Unavailable site'; }
		return get_blog_option( $id, 'blogname', $site->domain ) . ' — ' . $site->domain . $site->path;
	}
	public static function render( string $name, string $label, array $selected = array(), bool $multiple = false, string $empty = 'None', int $exclude = 0 ): void {
		wp_enqueue_script( 'brand-fleet-site-picker', BRAND_FLEET_URL . 'assets/site-picker.js', array(), BRAND_FLEET_VERSION, true );
		wp_enqueue_style( 'brand-fleet-site-picker', BRAND_FLEET_URL . 'assets/site-picker.css', array(), BRAND_FLEET_VERSION );
		wp_localize_script( 'brand-fleet-site-picker', 'brandFleetSitePicker', array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'brand_fleet_site_picker' ) ) );
		echo '<fieldset class="brand-fleet-site-picker" data-name="' . esc_attr( $name ) . '" data-multiple="' . ( $multiple ? '1' : '0' ) . '" data-exclude="' . esc_attr( (string) $exclude ) . '"><legend>' . esc_html( $label ) . '</legend><div class="brand-fleet-selected">';
		foreach ( array_filter( array_map( 'absint', $selected ) ) as $id ) {
			echo '<label><input type="checkbox" checked name="' . esc_attr( $name . ( $multiple ? '[]' : '' ) ) . '" value="' . esc_attr( (string) $id ) . '"> ' . esc_html( self::label( $id ) ) . '</label>';
		}
		echo '</div><details><summary>Choose ' . ( $multiple ? 'sites' : 'a site' ) . '</summary><label>Search by name or web address<input type="search" class="widefat brand-fleet-site-search" autocomplete="off"></label><p class="description">' . esc_html( $multiple ? 'Select multiple sites. Uncheck a selection to remove it.' : 'Choose one site. Clear the selection to use: ' . $empty . '.' ) . '</p><div class="brand-fleet-site-results"></div><p role="status" aria-live="polite"></p><button type="button" class="button brand-fleet-site-more">Show more</button></details><noscript>Enable JavaScript to browse sites. Existing selections can still be removed.</noscript></fieldset>';
	}
	public static function search(): void {
		check_ajax_referer( 'brand_fleet_site_picker', 'nonce' );
		if ( ! Fleet::network_admin() ) { wp_send_json_error( 'Network permission required.', 403 ); }
		$offset = min( 1000000, absint( $_POST['offset'] ?? 0 ) );
		// Page through sites, not an unbounded cross-site option query. Names use cached options.
		$sites = get_sites( array( 'network_id' => get_current_network_id(), 'number' => 26, 'offset' => $offset, 'orderby' => 'id', 'order' => 'ASC', 'deleted' => 0, 'archived' => 0, 'spam' => 0 ) );
		$items = array();
		foreach ( array_slice( $sites, 0, 25 ) as $site ) { $items[] = array( 'id' => (int) $site->blog_id, 'label' => self::label( (int) $site->blog_id ) ); }
		wp_send_json_success( array( 'items' => $items, 'more' => count( $sites ) > 25, 'offset' => $offset + 25 ) );
	}
}
