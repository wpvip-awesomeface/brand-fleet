<?php
/** Extend core Add Site and the installed MultilingualPress copy-site flow. */
namespace BrandFleet;

defined( 'ABSPATH' ) || exit;
class Fleet_Onboarding {
	private static ?array $pending = null;
	public function init(): void {
		add_action( 'wp_ajax_brand_fleet_site_defaults', array( $this, 'defaults' ) );
		add_action( 'network_site_new_form', array( $this, 'form' ) );
		add_action( 'load-site-new.php', array( $this, 'validate' ) );
		// MLP duplicates at priority 20 (25 in CLI), including the options table.
		add_action( 'wp_initialize_site', array( $this, 'created' ), PHP_INT_MAX, 1 );
	}
	public function form(): void {
		if ( ! Fleet::network_admin() ) { return; }
		echo '<h2>Site variables</h2><p>These values are applied after the selected starting site is copied. Network administrators can configure every site field, regardless of delegation.</p><p><label><input type="checkbox" name="brand_fleet_enroll" value="1">Manage this new site with Brand Fleet</label></p>';
		wp_enqueue_script( 'brand-fleet-onboarding', BRAND_FLEET_URL . 'assets/fleet-onboarding.js', array(), BRAND_FLEET_VERSION, true );
		wp_localize_script( 'brand-fleet-onboarding', 'brandFleetOnboarding', array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'brand_fleet_site_defaults' ) ) );
		wp_nonce_field( 'brand_fleet_create', 'brand_fleet_create_nonce' );
		Site_Picker::render( 'brand_fleet_group_id', 'Connected brand hub' );
		Site_Picker::render( 'brand_fleet_source_id', 'Main data source', array(), false, 'Network defaults' );
		echo '<p>Faint text previews inherited defaults. Leave a field empty to inherit, or type your own value. Fields marked “Enter a site-specific value” must be filled for this new site.</p><p id="brand-fleet-default-status" role="status"></p><p>The starting template is recorded from the existing “Based on site” copy selector.</p><table class="form-table"><tbody>';
		foreach ( Fleet::definitions() as $key => $d ) {
			echo '<tr><th scope="row">' . esc_html( $d['label'] ) . '<br><code>{{' . esc_html( $key ) . '}}</code>' . ( $d['required'] ? '<br>Required' : '' ) . '</th><td>';
			if ( 'network' === $d['scope'] ) { echo esc_html( $d['default'] ) . '<p>Network value — change in Brand Fleet → Variable definitions.</p>'; }
			else {
				$explicit = $d['required'] && $d['clone'];
				echo '<div class="brand-fleet-default-field" data-key="' . esc_attr( $key ) . '" data-default="' . esc_attr( $d['default'] ) . '" data-required="' . ( $d['required'] ? '1' : '0' ) . '" data-explicit="' . ( $explicit ? '1' : '0' ) . '"><textarea class="large-text" rows="2" name="brand_fleet_values[' . esc_attr( $key ) . ']" aria-label="' . esc_attr( $d['label'] ) . '" placeholder="' . esc_attr( $d['default'] ?: 'No inherited default' ) . '"></textarea><p class="description brand-fleet-default-hint">' . esc_html( $explicit ? 'Enter a site-specific value (required).' : ( $d['default'] ? 'Inherits network default unless you type a value.' : 'No inherited default set.' ) ) . '</p><label><input type="checkbox" name="brand_fleet_override[' . esc_attr( $key ) . ']" value="1"> Override inherited value (check to intentionally save a blank)</label></div>';

			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}
	public function defaults(): void {
		check_ajax_referer( 'brand_fleet_site_defaults', 'nonce' );
		if ( ! Fleet::network_admin() || ! current_user_can( 'create_sites' ) ) { wp_send_json_error( 'Network site creation permission required.', 403 ); }
		$source = absint( $_POST['source'] ?? 0 );
		if ( $source && ( ! Fleet::site( $source ) || ! empty( Fleet::profile( $source )['source_id'] ) ) ) { wp_send_json_error( 'Choose a main data site without its own data source.', 400 ); }
		$values = array();
		foreach ( Fleet::definitions() as $key => $d ) { $values[ $key ] = $source ? Fleet::resolved( $source, $key )['value'] : $d['default']; }
		wp_send_json_success( array( 'values' => $values ) );
	}
	/** Validate before core creates either a site or its administrator account. */
	public function validate(): void {
		if ( empty( $_POST['brand_fleet_enroll'] ) ) { return; }
		check_admin_referer( 'brand_fleet_create', 'brand_fleet_create_nonce' );
		if ( ! Fleet::network_admin() || ! current_user_can( 'create_sites' ) ) { wp_die( 'Network site creation permission required.' ); }
		try {
			$input = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Typed validation in prepare().
			self::$pending = self::prepare( $input );
		} catch ( \Throwable $e ) { wp_die( esc_html( $e->getMessage() ), 'Site variables', array( 'response' => 400, 'back_link' => true ) ); }
	}
	public static function prepare( array $in ): array {
		if ( ! Fleet::network_admin() ) { throw new \RuntimeException( 'Network permission required.' ); }
		$c = array( 'group_id' => absint( $in['brand_fleet_group_id'] ?? 0 ), 'source_id' => absint( $in['brand_fleet_source_id'] ?? 0 ), 'template_id' => absint( $in['mlp_based_on_site'] ?? 0 ) );
		foreach ( $c as $id ) { if ( $id && ! Fleet::site( $id ) ) { throw new \InvalidArgumentException( 'Choose active connections in this network.' ); } }
		if ( $c['source_id'] && ! empty( Fleet::profile( $c['source_id'] )['source_id'] ) ) { throw new \InvalidArgumentException( 'The main data site must not inherit from another site.' ); }
		$values = array();
		foreach ( Fleet::definitions() as $key => $d ) {
			if ( 'network' === $d['scope'] ) { $value = $d['default']; }
			elseif ( ! empty( $in['brand_fleet_override'][ $key ] ) || '' !== ( $in['brand_fleet_values'][ $key ] ?? '' ) ) { $value = Fleet::clean( $in['brand_fleet_values'][ $key ] ?? '', $d ); $values[ $key ] = $value; }
			else { $value = $c['source_id'] ? Fleet::resolved( $c['source_id'], $key )['value'] : $d['default']; }
			if ( $d['required'] && ( '' === trim( $value ) || ( 'location' === $d['scope'] && $d['clone'] && ! array_key_exists( $key, $values ) ) ) ) { throw new \InvalidArgumentException( 'Enter the required site field: ' . $d['label'] ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception is escaped by UI handler; API returns structured errors.
		}
		return array( 'connections' => $c, 'values' => $values );
	}
	/** Public adapter for a verified, completed synchronous clone. Never copies source identity. */
	public static function apply( int $site, array $setup ): void {
		if ( ! Fleet::network_admin() || ! Fleet::site( $site ) ) { throw new \RuntimeException( 'Network permission required.' ); }
		// Discard copied brand data before enrollment; other plugins/content are untouched.
		delete_blog_option( $site, Fleet::PROFILE );
		foreach ( array_keys( Settings::defaults() ) as $key ) { delete_blog_option( $site, $key ); }
		Fleet::save_profile( $site, $setup['values'], $setup['connections'] );
		delete_blog_option( $site, 'brand_fleet_setup_error' );
	}
	public function created( \WP_Site $site ): void {
		if ( null === self::$pending ) { return; }
		$setup = self::$pending; self::$pending = null;
		try { self::apply( (int) $site->blog_id, $setup ); }
		catch ( \Throwable $e ) {
			// A site now exists; preserve the error and hold it out of public view.
			update_blog_option( (int) $site->blog_id, 'brand_fleet_setup_error', $e->getMessage() );
			wp_update_site( (int) $site->blog_id, array( 'public' => 0, 'archived' => 1 ) );
			wp_die( esc_html( 'Site ' . $site->blog_id . ' was created but archived because brand setup failed: ' . $e->getMessage() ), 'Brand setup needs attention' );
		}
	}
}
