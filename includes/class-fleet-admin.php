<?php
/** Network registry, searchable sites, and local delegated fields. */
namespace BrandFleet;

defined( 'ABSPATH' ) || exit;
class Fleet_Admin {
	public function init(): void {
		add_action( 'wp_ajax_brand_fleet_site_picker', array( Site_Picker::class, 'search' ) );
		add_action( 'network_admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_menu', array( $this, 'main_site_menu' ) );
		add_action( 'admin_post_brand_fleet_save', array( $this, 'save' ) );
		add_action( 'admin_post_brand_fleet_export', array( $this, 'export' ) );
		add_action( 'admin_post_brand_fleet_export_values', array( $this, 'export_values' ) );
		add_action( 'wp_ajax_brand_fleet_batch', array( $this, 'batch' ) );
		add_action( 'wp_ajax_brand_fleet_replace_exclude', array( $this, 'exclude' ) );
		add_filter( 'network_admin_plugin_action_links_' . plugin_basename( BRAND_FLEET_DIR . 'brand-fleet.php' ), array( $this, 'network_action_links' ) );
	}
	public function menu(): void {
		add_menu_page( 'Brand Fleet', 'Brand Fleet', 'manage_network_options', 'brand-fleet', array( $this, 'render' ), 'dashicons-admin-multisite', 30 );
	}
	/** Central editors with fleet capabilities (granted under Access) work from the main site's dashboard. */
	public function main_site_menu(): void {
		if ( ! is_main_site() || Fleet::network_admin() || ! Fleet::can_any() ) { return; }
		add_menu_page( 'Brand Fleet', 'Brand Fleet', 'read', 'brand-fleet', array( $this, 'render' ), 'dashicons-admin-multisite', 30 );
	}
	/** Adds a "Manage fleet" shortcut to the plugin row in Network Admin → Plugins, before Deactivate. */
	public function network_action_links( array $actions ): array {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			return $actions;
		}
		$links = array( 'manage_fleet' => '<a href="' . esc_url( network_admin_url( 'admin.php?page=brand-fleet' ) ) . '">' . esc_html__( 'Manage fleet', 'brand-fleet' ) . '</a>' );
		$position = array_search( 'deactivate', array_keys( $actions ), true );
		if ( false === $position ) {
			return $links + $actions;
		}
		return array_slice( $actions, 0, $position, true ) + $links + array_slice( $actions, $position, null, true );
	}
	private static function url( array $args = array() ): string { return add_query_arg( $args, Fleet::network_admin() ? network_admin_url( 'admin.php?page=brand-fleet' ) : get_admin_url( (int) get_main_site_id(), 'admin.php?page=brand-fleet' ) ); }
	/** Tabs and the capability each needs. */
	const TABS = array( 'sites' => array( 'Sites', 'values' ), 'requests' => array( 'Requests', 'values' ), 'definitions' => array( 'Variables', 'manage' ), 'segments' => array( 'Segments', 'manage' ), 'seo' => array( 'Local SEO', 'manage' ), 'bulk' => array( 'Bulk update', 'bulk' ), 'replace' => array( 'Find & replace', 'bulk' ), 'import' => array( 'Import', 'bulk' ), 'overrides' => array( 'Overrides', 'values' ), 'tokens' => array( 'Token check', 'bulk' ), 'access' => array( 'Access', 'network' ), 'audit' => array( 'Activity', 'any' ) );
	private static function allowed( string $need ): bool {
		if ( 'any' === $need ) { return Fleet::can_any(); }
		return 'network' === $need ? Fleet::network_admin() : Fleet::can( $need );
	}
	public static function begin( string $task ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="brand_fleet_save"><input type="hidden" name="task" value="' . esc_attr( $task ) . '">';
		wp_nonce_field( 'brand_fleet_save' );
	}
	public static function field( string $name, string $label, string $value = '', string $type = 'text' ): void {
		echo '<p><label>' . esc_html( $label ) . '<br><input class="regular-text" type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"></label></p>';
	}
	private static function select( string $name, string $label, array $options, string $value ): void {
		echo '<p><label>' . esc_html( $label ) . '<br><select name="' . esc_attr( $name ) . '">';
		foreach ( $options as $key => $text ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $key, $value, false ) . '>' . esc_html( $text ) . '</option>'; }
		echo '</select></label></p>';
	}
	/** Color picker and Media Library support for typed value inputs; uploads go to the current site's library (VIP File Service). */
	public static function enqueue_fields(): void {
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style( 'brand-fleet-admin', BRAND_FLEET_URL . 'assets/fleet-admin.css', array(), BRAND_FLEET_VERSION );
		if ( current_user_can( 'upload_files' ) ) { wp_enqueue_media(); }
		wp_enqueue_script( 'brand-fleet-fields', BRAND_FLEET_URL . 'assets/fleet-fields.js', array( 'jquery', 'wp-color-picker' ), BRAND_FLEET_VERSION, true );
	}
	/** One input that matches the variable type. $attrs adds aria-label, placeholder or disabled. */
	public static function input( string $type, string $name, string $value, array $attrs = array(), array $options = array() ): void {
		$extra = '';
		foreach ( $attrs as $attr => $attr_value ) { $extra .= ' ' . esc_attr( $attr ) . ( true === $attr_value ? '' : '="' . esc_attr( (string) $attr_value ) . '"' ); }
		$base = 'name="' . esc_attr( $name ) . '"' . $extra;
		switch ( $type ) {
			case 'textarea':
				echo '<textarea class="large-text" rows="3" ' . $base . '>' . esc_textarea( $value ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $base is built from escaped parts above.
				return;
			case 'hours':
				// Saved hours come back one day per line, the way people type them.
				echo '<textarea class="large-text code" rows="5" placeholder="' . esc_attr( Fleet_Hours::EXAMPLE ) . '" ' . $base . '>' . esc_textarea( Fleet_Hours::editable( $value ) ) . '</textarea><span class="description">One day or date per line, for example <code>Mon-Fri 9:00-17:00</code>, <code>Sat 10am-2pm</code>, <code>Sun closed</code> or <code>2026-12-24 9-12</code>.</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $base is built from escaped parts above.
				return;
			case 'list':
				echo '<textarea class="large-text" rows="4" placeholder="' . esc_attr( "First item\nSecond item" ) . '" ' . $base . '>' . esc_textarea( $value ) . '</textarea><span class="description">One item per line.</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $base is built from escaped parts above.
				return;
			case 'embed':
				echo '<textarea class="large-text code" rows="6" spellcheck="false" ' . $base . '>' . esc_textarea( $value ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $base is built from escaped parts above.
				return;
			case 'select':
			case 'toggle':
				$choices = 'toggle' === $type ? array( '1' => $options[0] ?? 'Yes', '0' => $options[1] ?? 'No' ) : array_combine( $options, $options );
				echo '<select data-choices="' . esc_attr( $type ) . '" ' . $base . '><option value="">— None —</option>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $base is built from escaped parts above.
				foreach ( (array) $choices as $choice => $text ) { echo '<option value="' . esc_attr( (string) $choice ) . '"' . selected( (string) $choice, $value, false ) . '>' . esc_html( (string) $text ) . '</option>'; }
				echo '</select>';
				return;
			case 'color':
				echo '<input type="text" class="brand-fleet-color" maxlength="7" ' . $base . ' value="' . esc_attr( $value ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $base is built from escaped parts above.
				return;
			case 'image':
				echo '<span class="brand-fleet-image"><img class="brand-fleet-image-preview" alt="" src="' . esc_url( $value ) . '"' . ( $value ? '' : ' hidden' ) . '><input type="url" class="large-text" placeholder="https://" ' . $base . ' value="' . esc_attr( $value ) . '"><span class="brand-fleet-image-actions">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $base is built from escaped parts above.
				if ( current_user_can( 'upload_files' ) ) { echo '<button type="button" class="button brand-fleet-image-choose">Choose or upload image</button> '; }
				echo '<button type="button" class="button-link brand-fleet-image-clear"' . ( $value ? '' : ' hidden' ) . '>Remove image</button></span></span>';
				return;
		}
		$types = array( 'url' => 'url', 'email' => 'email', 'phone' => 'tel', 'number' => 'number', 'date' => 'date' );
		echo '<input type="' . esc_attr( $types[ $type ] ?? 'text' ) . '" class="' . ( in_array( $type, array( 'email', 'phone', 'number', 'date' ), true ) ? 'regular-text' : 'large-text' ) . '"' . ( 'number' === $type ? ' step="any"' : '' ) . ( 'url' === $type ? ' placeholder="https://"' : '' ) . ' ' . $base . ' value="' . esc_attr( $value ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $base is built from escaped parts above.
	}
	/**
	 * One input per panel ( id => [ type, options ] ); only the panel matching the $control
	 * select's value is shown and enabled, so disabled inputs are never submitted.
	 */
	private static function typed_inputs( string $control, string $name, array $panels, string $selected, string $value = '', array $attrs = array() ): void {
		echo '<span class="brand-fleet-typed" data-control="' . esc_attr( $control ) . '">';
		foreach ( $panels as $panel => $spec ) {
			$on = (string) $panel === $selected;
			echo '<span data-panel="' . esc_attr( (string) $panel ) . '"' . ( $on ? '' : ' hidden' ) . '>';
			self::input( $spec[0], $name, $on ? $value : '', $attrs + ( $on ? array() : array( 'disabled' => true ) ), $spec[1] ?? array() );
			echo '</span>';
		}
		echo '</span>';
	}
	/** Definitions grouped by their optional group, ungrouped first. */
	private static function grouped( array $defs ): array {
		$groups = array();
		foreach ( $defs as $key => $d ) { $groups[ (string) ( $d['group'] ?? '' ) ][ $key ] = $d; }
		uksort( $groups, static fn( $a, $b ) => ( '' === $a ? -1 : ( '' === $b ? 1 : strcasecmp( $a, $b ) ) ) );
		return $groups;
	}
	private static function token( string $key ): string {
		return '<code>{{' . esc_html( $key ) . '}}</code> <button type="button" class="button-link brand-fleet-copy" data-copy="{{' . esc_attr( $key ) . '}}">Copy</button>';
	}
	/** Plain-language summary of who sets a value. */
	private static function policy_label( array $d ): string {
		if ( 'embed' === $d['type'] ) { return 'Network admins (embed)'; }
		if ( 'network' === $d['scope'] ) { return $d['segment_defaults'] ?? array() ? 'Set by the network, per segment' : 'Same on every site'; }
		if ( 'imported' === $d['scope'] ) { return 'Each site — from an import'; }
		return array( 'all' => 'Each site — site admins can edit', 'selected' => 'Each site — selected sites can edit', 'none' => 'Each site — network admins edit' )[ $d['access'] ] ?? '';
	}
	/** Read-only display: swatches for colors, thumbnails for images. */
	public static function preview( string $type, string $value, array $d = array() ): string {
		if ( '' === $value ) { return '<em>Empty</em>'; }
		if ( 'embed' === $type ) { return esc_html( Fleet::PLACEMENTS[ $d['placement'] ?? 'head' ] ?? '' ) . '<br><small>' . esc_html( empty( $d['targets'] ) ? 'All enrolled sites' : count( $d['targets'] ) . ' selected sites' ) . '</small>'; }
		if ( $d && in_array( $type, array( 'toggle', 'date' ), true ) ) { return esc_html( Fleet::display( $d, $value ) ); }
		if ( 'hours' === $type ) { try { $hours = Fleet_Hours::parse( $value ); } catch ( \InvalidArgumentException $e ) { return esc_html( $value ); } return implode( '<br>', array_map( static fn( $row ) => '<strong>' . esc_html( $row['days'] ) . '</strong> ' . esc_html( $row['times'] ), Fleet_Hours::weekly_rows( $hours ) ) ) . ( $hours['dates'] ? '<br><small>' . esc_html( sprintf( _n( '%d special date', '%d special dates', count( $hours['dates'] ), 'brand-fleet' ), count( $hours['dates'] ) ) ) . '</small>' : '' ); }
		if ( 'list' === $type ) { $items = Fleet::items( $value ); return esc_html( implode( ', ', array_slice( $items, 0, 5 ) ) ) . ( count( $items ) > 5 ? esc_html( sprintf( ' +%d more', count( $items ) - 5 ) ) : '' ); }
		if ( 'color' === $type && sanitize_hex_color( $value ) ) { return '<span class="brand-fleet-swatch" style="background:' . esc_attr( $value ) . '"></span> <code>' . esc_html( $value ) . '</code>'; }
		if ( 'image' === $type ) { return '<img class="brand-fleet-thumb" alt="" src="' . esc_url( $value ) . '"><br><a href="' . esc_url( $value ) . '" target="_blank" rel="noopener">' . esc_html( wp_basename( (string) wp_parse_url( $value, PHP_URL_PATH ) ) ) . '</a>'; }
		return nl2br( esc_html( $value ) );
	}
	public static function values( int $site ): void {
		self::enqueue_fields();
		$p = Fleet::profile( $site );
		$original_hash = Fleet::hash( $p );
		if ( ! $p ) {
			foreach ( Fleet::definitions() as $key => $d ) {
				$legacy = get_blog_option( $site, 'brand_fleet_' . $key, '' );
				if ( is_string( $legacy ) && '' !== $legacy ) { $p['values'][ $key ] = $legacy; }
			}
		}
		$sources = array( 'network' => 'Network default', 'location' => 'Set on this site', 'existing site setting' => 'Existing site setting' );
		echo '<input type="hidden" name="expected" value="' . esc_attr( $original_hash ) . '"><table class="widefat brand-fleet-values"><thead><tr><th scope="col">Variable</th><th scope="col">Current value</th><th scope="col">This site\'s value</th></tr></thead>';
		foreach ( self::grouped( array_filter( Fleet::definitions(), static fn( $d ) => 'embed' !== $d['type'] ) ) as $group => $defs ) {
			echo '<tbody>' . ( '' !== $group ? '<tr class="brand-fleet-group"><th colspan="3" scope="colgroup">' . esc_html( $group ) . '</th></tr>' : '' );
			foreach ( $defs as $key => $d ) {
				if ( ! Fleet::can( 'values' ) && ! Fleet::editable( $d, $site ) ) { continue; }
				$r = Fleet::resolved( $site, $key );
				$editable = 'location' === $d['scope'] && ( Fleet::can( 'values' ) || Fleet::editable( $d, $site ) );
				$source = $sources[ $r['source'] ] ?? ( str_starts_with( (string) $r['source'], 'segment ' ) ? 'Segment default: ' . ( Fleet_Segments::all()[ substr( (string) $r['source'], 8 ) ]['label'] ?? substr( (string) $r['source'], 8 ) ) : ucfirst( str_replace( 'main site', 'Inherited from site', (string) $r['source'] ) ) );
				echo '<tr><th scope="row"><strong>' . esc_html( $d['label'] ) . '</strong>' . ( $d['required'] ? ' <span class="brand-fleet-tag">Required</span>' : '' ) . ( ! empty( $d['help'] ) ? '<p class="description">' . esc_html( $d['help'] ) . '</p>' : '' ) . self::token( $key ) . '</th><td>' . self::preview( $d['type'], (string) $r['value'], $d ) . '<br><small class="brand-fleet-source">' . esc_html( $source ) . '</small></td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- token() and preview() escape their output.
				if ( $editable ) {
					echo '<div class="brand-fleet-override" data-override="override[' . esc_attr( $key ) . ']"><label><input type="checkbox" name="override[' . esc_attr( $key ) . ']" value="1" ' . checked( array_key_exists( $key, $p['values'] ?? array() ), true, false ) . '> Use a value just for this site</label><br>';
					self::input( $d['type'], 'values[' . $key . ']', (string) ( $p['values'][ $key ] ?? '' ), array( 'aria-label' => $d['label'] ), $d['options'] ?? array() );
					echo '</div>';
				} else { echo '<span class="description">' . esc_html( 'imported' === $d['scope'] ? 'Comes from an import. Change it in the source spreadsheet.' : 'Set by the network. Change it under Variables.' ) . '</span>'; }
				echo '</td></tr>';
			}
			echo '</tbody>';
		}
		echo '</table>';
	}
	/** Recent changes to one site's own values, newest first, each with a way back. */
	public static function history( int $site ): void {
		$log = Fleet_History::get( $site ); $defs = Fleet::definitions();
		if ( ! $log ) { return; }
		echo '<details class="brand-fleet-panel brand-fleet-history"><summary>' . esc_html( sprintf( 'Value history (%d changes)', count( $log ) ) ) . '</summary><p class="description">The last 100 changes to this site\'s own values. Restore puts one value back the way it was before that change.</p><table class="widefat striped"><thead><tr><th scope="col">When (UTC)</th><th scope="col">Who</th><th scope="col">Variable</th><th scope="col">Before</th><th scope="col">After</th><th scope="col">How</th><th scope="col"><span class="screen-reader-text">Restore</span></th></tr></thead><tbody>';
		$shown = 0;
		foreach ( array_reverse( $log, true ) as $index => $entry ) {
			if ( ++$shown > 50 ) { break; }
			$d = $defs[ $entry['key'] ] ?? array( 'type' => 'text', 'label' => $entry['key'] );
			$user = get_userdata( (int) $entry['user'] );
			$value = static fn( $v ) => null === $v ? '<span class="description">Shared value</span>' : self::preview( $d['type'], (string) $v, $d );
			echo '<tr><td>' . esc_html( gmdate( 'M j, Y H:i', strtotime( $entry['time'] ) ) ) . '</td><td>' . esc_html( $user ? $user->display_name : '—' ) . '</td><td>' . esc_html( $d['label'] ) . '</td><td>' . $value( $entry['old'] ) . '</td><td>' . $value( $entry['new'] ) . '</td><td>' . esc_html( Fleet_History::VIA[ $entry['via'] ] ?? $entry['via'] ) . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- preview() escapes its output.
			if ( isset( $defs[ $entry['key'] ] ) && empty( $entry['long'] ) && ( Fleet::can( 'values' ) || Fleet::editable( $d, $site ) ) ) {
				self::begin( 'restore' );
				echo '<input type="hidden" name="site_id" value="' . esc_attr( (string) $site ) . '"><input type="hidden" name="index" value="' . esc_attr( (string) $index ) . '"><input type="hidden" name="entry" value="' . esc_attr( Fleet::hash( $entry ) ) . '"><button class="button button-small" aria-label="' . esc_attr( 'Restore ' . $d['label'] . ' to its value before this change' ) . '">Restore</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></details>';
	}
	public function local(): void {
		echo '<div class="wrap brand-fleet"><h1>Brand Identity</h1><p>These values fill in the {{tokens}} on your site. Check “Use a value just for this site” to set your own; uncheck it to go back to the shared value. Only fields your network lets you edit are shown.</p>';
		self::begin( 'site' );
		echo '<input type="hidden" name="site_id" value="' . esc_attr( (string) get_current_blog_id() ) . '">';
		self::values( get_current_blog_id() ); submit_button( 'Save site variables' ); echo '</form>';
		self::history( get_current_blog_id() );
		self::request_form( get_current_blog_id() ); echo '</div>';
	}
	public function render(): void {
		if ( ! Fleet::can_any() ) { return; }
		$tabs = array();
		foreach ( self::TABS as $key => $tab_spec ) { if ( self::allowed( $tab_spec[1] ) ) { $tabs[ $key ] = $tab_spec[0]; } }
		// Read-only navigation parameters; all writes use separate nonce-protected handlers.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $tabs[ $tab ] ) ) { $tab = (string) array_key_first( $tabs ); }
		wp_enqueue_style( 'brand-fleet-admin', BRAND_FLEET_URL . 'assets/fleet-admin.css', array(), BRAND_FLEET_VERSION );
		echo '<div class="wrap brand-fleet"><h1>Brand Fleet</h1><nav class="nav-tab-wrapper" aria-label="Brand Fleet sections">';
		$open = isset( $tabs['requests'] ) ? count( Fleet_Requests::pending() ) : 0;
		foreach ( $tabs as $key => $label ) { echo '<a class="nav-tab' . ( $key === $tab ? ' nav-tab-active" aria-current="page' : '' ) . '" href="' . esc_url( self::url( array( 'tab' => $key ) ) ) . '">' . esc_html( $label ) . ( 'requests' === $key && $open ? ' <span class="brand-fleet-count" aria-label="' . esc_attr( sprintf( '%d open', $open ) ) . '">' . esc_html( (string) $open ) . '</span>' : '' ) . '</a>'; } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each part is escaped inline.
		echo '</nav>';
		self::enqueue_fields();
		if ( 'definitions' === $tab ) { $this->definitions(); }
		elseif ( 'bulk' === $tab ) { $this->bulk(); }
		elseif ( 'replace' === $tab ) { $this->replace(); }
		elseif ( 'segments' === $tab ) { $this->segments(); }
		elseif ( 'seo' === $tab ) { $this->seo(); }
		elseif ( 'import' === $tab ) { $this->import(); }
		elseif ( 'overrides' === $tab ) { $this->overrides(); }
		elseif ( 'requests' === $tab ) { $this->requests(); }
		elseif ( 'tokens' === $tab ) { $this->tokens(); }
		elseif ( 'access' === $tab ) { $this->access(); }
		elseif ( 'audit' === $tab ) { $this->activity(); }
		else { $this->sites(); }
		echo '</div>';
	}
	private function activity(): void {
		$current = Fleet_Jobs::get();
		$batch = isset( $_GET['batch'] ) ? sanitize_text_field( wp_unslash( $_GET['batch'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only batch view.
		if ( $current && $batch === $current['id'] && in_array( $current['kind'] ?? '', array( 'undo', 'revert' ), true ) ) { $this->undo_view( $current ); return; }
		$log = array_reverse( (array) get_network_option( get_current_network_id(), 'brand_fleet_audit', array() ) );
		$groups = array(); $defs = Fleet::definitions();
		foreach ( $log as $row ) {
			$keys = $row['keys'] ?? array(); sort( $keys ); $row['keys'] = $keys;
			$last = count( $groups ) - 1;
			// Legacy events have no batch identity: only collapse adjacent edits by the same actor/key set within five minutes.
			if ( 'site' === $row['action'] && $last >= 0 && 'site' === $groups[ $last ]['action'] && $row['user'] === $groups[ $last ]['user'] && $keys === $groups[ $last ]['keys'] && abs( strtotime( $groups[ $last ]['time'] ) - strtotime( $row['time'] ) ) <= 300 ) {
				$groups[ $last ]['sites'][] = $row['site']; continue;
			}
			$row['sites'] = empty( $row['site'] ) ? array() : array( $row['site'] ); $groups[] = $row;
		}
		$query = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search.
		if ( $query ) {
			$groups = array_values( array_filter( $groups, static function ( $row ) use ( $query, $defs ) {
				$user = get_userdata( (int) $row['user'] );
				$labels = array_map( static fn( $key ) => $defs[ $key ]['label'] ?? $key, $row['keys'] );
				$text = implode( ' ', array_merge( $row['keys'], $labels, array( $row['time'], gmdate( 'M j, Y', strtotime( $row['time'] ) ), $user ? $user->display_name . ' ' . $user->user_login : 'Deleted user', $row['action'], $row['phase'] ?? '', 'definition' === $row['action'] ? 'Variable definition saved' : ( 'import' === $row['action'] ? 'Definitions imported' : ( 'bulk' === $row['action'] ? 'Bulk update' : 'Site updated similar edits' ) ) ) ) );
				return false !== stripos( $text, $query );
			} ) );
		}
		$page = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<h2>Recent activity</h2><form method="get" class="brand-fleet-search-form"><input type="hidden" name="page" value="brand-fleet"><input type="hidden" name="tab" value="audit"><label>Search activity<input type="search" name="q" value="' . esc_attr( $query ) . '" placeholder="Variable, person, date or activity type"></label><button class="button">Search</button><a class="button" href="' . esc_url( self::url( array( 'tab' => 'audit' ) ) ) . '">Clear</a></form><p>' . esc_html( (string) count( $groups ) ) . ' matching activities · newest first. Dates can be searched as YYYY-MM-DD.</p>';
		echo '<h2 class="screen-reader-text">Recent activity</h2><p>Bulk runs appear once, with progress and results. Adjacent individual edits by the same person to the same variables within five minutes are grouped as similar edits. The latest 100 stored activities are retained; site samples show up to 20 results.</p><table class="widefat striped"><thead><tr><th scope="col">When (UTC)</th><th scope="col">Who</th><th scope="col">Activity</th><th scope="col">Variables</th><th scope="col">Sites and results</th></tr></thead><tbody>';
		foreach ( array_slice( $groups, ( $page - 1 ) * 20, 20 ) as $row ) {
			$user = get_userdata( (int) $row['user'] );
			$labels = array_map( static fn( $key ) => $defs[ $key ]['label'] ?? $key, $row['keys'] );
			$is_bulk = 'bulk' === $row['action'];
			$labels_by_action = array( 'definition' => 'Variable definition saved', 'import' => 'Definitions imported', 'meta-keys' => 'Custom fields updated', 'segments' => 'Segments saved', 'access' => 'Access changed', 'closures' => 'Holiday closures saved', 'local-seo' => 'Local SEO settings saved', 'alerts' => 'Email alerts changed', 'export-values' => 'Values downloaded', 'request' => 'Change requested', 'request-approved' => 'Change request approved', 'request-declined' => 'Change request declined' );
			$action = $is_bulk ? ( isset( $row['label'] ) ? $row['label'] . ( 'complete' === $row['phase'] ? '' : ' (' . ( 'apply' === $row['phase'] ? 'in progress' : $row['phase'] ) . ')' ) : ( isset( $row['replace'] ) ? 'Find & replace: “' . $row['replace']['find'] . '” → “' . $row['replace']['with'] . '”' . ( 'complete' === $row['phase'] ? '' : ' (in progress / paused)' ) : ( 'complete' === $row['phase'] ? 'Bulk update completed' : 'Bulk update in progress / paused' ) ) ) : ( $labels_by_action[ $row['action'] ] ?? ( count( $row['sites'] ) > 1 ? 'Similar site edits' : 'Site updated' ) );
			echo '<tr><td>' . esc_html( gmdate( 'M j, Y H:i', strtotime( $row['time'] ) ) ) . '</td><td>' . esc_html( $user ? $user->display_name : 'Deleted user' ) . '</td><td>' . esc_html( $action ) . '</td><td>' . esc_html( $labels ? implode( ', ', $labels ) : 'Connections / enrollment' ) . '</td><td>';
			if ( $is_bulk ) {
				echo esc_html( sprintf( '%d sites: %d updated, %d skipped, %d pending', $row['total'], $row['updated'], $row['skipped'], max( 0, $row['total'] - $row['updated'] - $row['skipped'] ) ) );
				self::undo_button( (string) $row['batch'] );
				if ( $row['details'] ) {
					echo '<details><summary>View site sample</summary><ul>';
					foreach ( $row['details'] as $detail ) { echo '<li>' . esc_html( Site_Picker::label( (int) $detail['site'] ) . ' — ' . $detail['status'] . ( isset( $detail['error'] ) ? ': ' . $detail['error'] : '' ) ) . '</li>'; }
					echo '</ul></details>';
				}
			} elseif ( $row['sites'] ) {
				$sites = array_unique( $row['sites'] );
				if ( count( $sites ) === 1 ) { echo esc_html( Site_Picker::label( (int) reset( $sites ) ) ); }
				else { echo esc_html( count( $sites ) . ' sites' ) . '<details><summary>View sites</summary><ul>'; foreach ( array_slice( $sites, 0, 20 ) as $id ) { echo '<li>' . esc_html( Site_Picker::label( (int) $id ) ) . '</li>'; } echo '</ul></details>'; }
			} else { echo 'Network variable configuration'; }
			echo '</td></tr>';
		}
		if ( ! $groups ) { echo '<tr><td colspan="5">No matching activity. Try another variable, person or date, or clear the search.</td></tr>'; }
		echo '</tbody></table><p>';
		if ( $page > 1 ) { echo '<a class="button" href="' . esc_url( self::url( array( 'tab' => 'audit', 'q' => $query, 'paged' => $page - 1 ) ) ) . '">Newer activity</a> '; }
		if ( count( $groups ) > $page * 20 ) { echo '<a class="button" href="' . esc_url( self::url( array( 'tab' => 'audit', 'q' => $query, 'paged' => $page + 1 ) ) ) . '">Older activity</a>'; }
		echo '</p>';
	}
	private function definitions(): void {
		$search = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit = isset( $_GET['key'] ) ? sanitize_key( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$defs = Fleet::definitions(); $d = ( $defs[ $edit ] ?? array() ) + Fleet::EXTRAS;
		if ( isset( $_GET['saved'] ) ) { echo '<div class="notice notice-success"><p>Saved.</p></div>'; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice.
		elseif ( isset( $_GET['imported'] ) ) { $count = absint( $_GET['imported'] ); echo '<div class="notice notice-success"><p>' . esc_html( $count > 0 ? sprintf( 'Imported %d definition(s).', $count ) : 'Nothing to import — all keys were unchanged or rejected.' ) . '</p></div>'; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice.
		if ( 'import' === $view ) { $this->import_view(); return; }
		$new = isset( $_GET['new_variable'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		if ( ! $edit && ! $new ) {
			echo '<h2>Variables <a class="page-title-action" href="' . esc_url( self::url( array( 'tab' => 'definitions', 'new_variable' => 1 ) ) ) . '">Add variable</a> <a class="page-title-action" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=brand_fleet_export' ), 'brand_fleet_export' ) ) . '">Export</a> <a class="page-title-action" href="' . esc_url( self::url( array( 'tab' => 'definitions', 'view' => 'import' ) ) ) . '">Import</a></h2>';
			echo '<p class="brand-fleet-intro">A variable is a piece of information your sites share, like a phone number, logo or brand color. Type its token, for example <code>{{business_name}}</code>, into any page and each site shows its own value. Embed code adds a script, such as analytics, to every site.</p>';
			echo '<form method="get" class="brand-fleet-search-form"><input type="hidden" name="page" value="brand-fleet"><input type="hidden" name="tab" value="definitions"><p><label>Search variables <input type="search" name="q" value="' . esc_attr( $search ) . '"></label> <button class="button">Search</button></p></form>';
			$filtered = array_filter( $defs, static fn( $def, $key ) => ! $search || false !== stripos( $key . ' ' . $def['label'] . ' ' . ( $def['group'] ?? '' ), $search ), ARRAY_FILTER_USE_BOTH );
			$page = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<p>' . esc_html( (string) count( $filtered ) ) . ' variables</p><table class="widefat brand-fleet-values"><thead><tr><th scope="col">Variable</th><th scope="col">Type</th><th scope="col">Default value</th><th scope="col">Who sets it</th><th scope="col">New sites</th></tr></thead>';
			$sorted = array();
			foreach ( self::grouped( $filtered ) as $group_defs ) { $sorted += $group_defs; }
			foreach ( self::grouped( array_slice( $sorted, ( $page - 1 ) * 20, 20, true ) ) as $group => $group_defs ) {
				echo '<tbody>' . ( '' !== $group ? '<tr class="brand-fleet-group"><th colspan="5" scope="colgroup">' . esc_html( $group ) . '</th></tr>' : '' );
				foreach ( $group_defs as $key => $def ) {
					echo '<tr><td><strong><a href="' . esc_url( self::url( array( 'tab' => 'definitions', 'key' => $key ) ) ) . '">' . esc_html( $def['label'] ) . '</a></strong><br>' . ( 'embed' === $def['type'] ? '<span class="description">Prints on sites automatically</span>' : self::token( $key ) ) . '</td><td>' . esc_html( Fleet::TYPES[ $def['type'] ] ?? ucfirst( $def['type'] ) ) . '</td><td>' . self::preview( $def['type'], (string) $def['default'], $def ) . '</td><td>' . esc_html( self::policy_label( $def ) ) . '</td><td>' . esc_html( implode( ' · ', array_filter( array( $def['required'] ? 'Required' : '', $def['clone'] ? 'Asked on Add Site' : '' ) ) ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- token() and preview() escape their output.
				}
				echo '</tbody>';
			}
			if ( ! $filtered ) { echo '<tbody><tr><td colspan="5">No variables found. Try another search or add a variable.</td></tr></tbody>'; }
			echo '</table><p>';
			if ( $page > 1 ) { echo '<a class="button" href="' . esc_url( self::url( array( 'tab' => 'definitions', 'q' => $search, 'paged' => $page - 1 ) ) ) . '">Previous</a> '; }
			if ( count( $filtered ) > $page * 20 ) { echo '<a class="button" href="' . esc_url( self::url( array( 'tab' => 'definitions', 'q' => $search, 'paged' => $page + 1 ) ) ) . '">Next</a>'; }
			echo '</p>';
			echo '<details class="brand-fleet-panel"><summary>Where variables work</summary><p>Tokens work in page content, headers and footers, post titles, excerpts, and SEO titles and descriptions (Yoast and Rank Math). Links, images and colors also work inside block settings, such as an image source or a button link. In the block editor, a heading, paragraph, image or button can be connected to a variable under <strong>Attributes</strong> in the block settings (gear) tab.</p>';
			self::begin( 'meta-keys' );
			echo '<p><label>Also use variables in these custom fields<br><textarea class="large-text code" rows="2" name="meta_keys" placeholder="for example: hero_subtitle, store_hours">' . esc_textarea( implode( ', ', Fleet::meta_keys() ) ) . '</textarea></label></p><p class="description">Enter custom field (post meta) keys, separated by commas. On the live site, {{tokens}} in these fields show each site\'s own value. Up to 50 fields.</p>';
			submit_button( 'Save custom fields', 'secondary' ); echo '</form></details>';
			self::closures_panel();
			return;
		}
		echo '<p><a href="' . esc_url( self::url( array( 'tab' => 'definitions' ) ) ) . '">← All variables</a></p>';
		if ( $edit && ! isset( $defs[ $edit ] ) ) { echo '<p>This variable was not found.</p>'; return; }
		$type = $edit ? $d['type'] : 'text';
		echo '<h2>' . esc_html( $edit ? 'Edit ' . $d['label'] : 'Add variable' ) . '</h2>'; self::begin( 'definition' );
		echo '<div class="brand-fleet-definition-form"><h3>1. Name it</h3>';
		self::field( 'label', 'Name (shown to editors)', $d['label'] ?? '' );
		if ( $edit ) { echo '<p>Token: ' . self::token( $edit ) . '</p><input type="hidden" name="key" value="' . esc_attr( $edit ) . '">'; } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- token() escapes its output.
		else { echo '<p><label>Token key<br><code>{{</code><input class="regular-text code" type="text" name="key" pattern="[a-z][a-z0-9_]{0,47}" placeholder="opening_hours" data-key-from="label" required><code>}}</code></label><br><span class="description">Lowercase letters, numbers and underscores. It cannot change later, because pages use it.</span></p>'; }
		$groups = array_values( array_unique( array_filter( array_map( static fn( $x ) => (string) ( $x['group'] ?? '' ), $defs ) ) ) );
		echo '<p><label>Group (optional)<br><input class="regular-text" type="text" name="group" list="brand-fleet-groups" maxlength="40" value="' . esc_attr( $d['group'] ) . '" placeholder="for example Contact, Branding, Hours"></label></p><datalist id="brand-fleet-groups">';
		foreach ( $groups as $group ) { echo '<option value="' . esc_attr( $group ) . '">'; }
		echo '</datalist><p><label>Help text for editors (optional)<br><input class="large-text" type="text" name="help" maxlength="200" value="' . esc_attr( $d['help'] ) . '"></label></p>';
		echo '<h3>2. Choose what kind of value</h3>';
		if ( $edit ) { echo '<p><strong>' . esc_html( Fleet::TYPES[ $type ] ?? $type ) . '</strong> <span class="description">(cannot change after creation)</span></p><input type="hidden" name="type" value="' . esc_attr( $type ) . '">'; }
		else {
			$hints = array( 'text' => 'One line of text.', 'textarea' => 'Several lines, like an address.', 'url' => 'A web address.', 'image' => 'A logo or photo: paste a link or upload one.', 'email' => 'An email address.', 'phone' => 'A phone number; also works in tel: links.', 'color' => 'A brand color with a color picker.', 'number' => 'A number.', 'select' => 'Pick one choice from a list you define.', 'toggle' => 'Yes or no, shown with your own wording.', 'date' => 'A date, shown in each site\'s date format.', 'hours' => 'Opening hours, typed like Mon-Fri 9-5. Powers the Hours block, open-now and local search.', 'list' => 'Several items, like services or providers, one per line.', 'embed' => 'Script or HTML (analytics, chat) added to every site. Network admins only.' );
			echo '<p><label>Type<br><select name="type">';
			foreach ( Fleet::TYPES as $value => $label ) {
				if ( 'embed' === $value && ! current_user_can( 'unfiltered_html' ) ) { continue; }
				echo '<option value="' . esc_attr( $value ) . '"' . selected( $value, $type, false ) . ' data-hint="' . esc_attr( $hints[ $value ] ?? '' ) . '">' . esc_html( $label ) . '</option>';
			}
			echo '</select></label> <span class="description brand-fleet-type-hint" aria-live="polite">' . esc_html( $hints[ $type ] ) . '</span></p>';
		}
		echo '<div data-show-when="type=select"><p><label>Choices, one per line<br><textarea class="large-text" rows="4" name="options">' . esc_textarea( 'select' === $type ? implode( "\n", $d['options'] ) : '' ) . '</textarea></label></p></div>';
		echo '<div data-show-when="type=toggle"><p><label>Wording for yes and no, one per line<br><textarea class="large-text" rows="2" name="options"' . ( 'toggle' === $type ? '' : ' disabled' ) . '>' . esc_textarea( 'toggle' === $type ? implode( "\n", $d['options'] ) : "Yes\nNo" ) . '</textarea></label></p><p class="description">For example “Open on weekends” and “Closed on weekends”. The token shows the matching line.</p></div>';
		echo '<h3>3. Set the value</h3><div class="brand-fleet-field"><span class="brand-fleet-field-label" data-show-when="type!=embed">Default value (used by every site that does not set its own)</span><span class="brand-fleet-field-label" data-show-when="type=embed">Embed code</span>';
		$panels = array();
		foreach ( array_keys( Fleet::TYPES ) as $t ) { $panels[ $t ] = array( $t, $t === $type ? $d['options'] : ( 'toggle' === $t ? array( 'Yes', 'No' ) : array() ) ); }
		self::typed_inputs( 'type', 'default', $panels, $type, (string) ( $d['default'] ?? '' ), array( 'aria-label' => 'Default value' ) );
		echo '</div>';
		$segments = Fleet_Segments::all();
		if ( $segments ) {
			echo '<details class="brand-fleet-panel" data-show-when="type!=embed"' . ( empty( $d['segment_defaults'] ) ? '' : ' open' ) . '><summary>Values by segment (optional)</summary><p class="description">Sites in a segment use its value instead of the default, unless they have their own. When a site is in several segments, the first one in the Segments list wins. Leave empty to use the default.</p><table class="form-table"><tbody>';
			foreach ( $segments as $slug => $segment ) {
				if ( isset( $segment['rules'][ $edit ] ) ) { continue; }
				echo '<tr><th scope="row">' . esc_html( $segment['label'] ) . '</th><td>';
				self::typed_inputs( 'type', 'segment_defaults[' . $slug . ']', $panels, $type, (string) ( $d['segment_defaults'][ $slug ] ?? '' ), array( 'aria-label' => $segment['label'] . ' value' ) );
				echo '</td></tr>';
			}
			echo '</tbody></table></details>';
		}
		if ( current_user_can( 'unfiltered_html' ) ) {
			echo '<div data-show-when="type=embed" class="brand-fleet-embed-settings"><p class="description">Use {{tokens}} for per-site values, for example <code>gtag(\'config\', \'{{analytics_id}}\')</code>. Inside embeds, a token only fills in when its value is a plain ID or link (letters, numbers and <code>_ - . : / # ? = &amp;</code>), so a site can never change the code itself.</p>';
			self::select( 'placement', 'Where it goes', Fleet::PLACEMENTS, (string) $d['placement'] );
			Site_Picker::render( 'targets', 'Only on these sites (leave empty for every enrolled site)', $d['targets'], true );
			if ( Fleet_Segments::all() ) { self::select( 'segment', 'Only on sites in this segment', array( '' => 'Any segment' ) + array_map( static fn( $x ) => $x['label'], Fleet_Segments::all() ), (string) ( $d['segment'] ?? '' ) ); }
			echo '</div>';
		}
		$policy = in_array( $d['scope'] ?? 'location', array( 'network', 'imported' ), true ) ? $d['scope'] : array( 'none' => 'admins', 'all' => 'all', 'selected' => 'selected' )[ $d['access'] ?? 'none' ];
		echo '<div data-show-when="type!=embed"><h3>4. Decide who sets it</h3><fieldset class="brand-fleet-choices"><legend class="screen-reader-text">Who sets the value</legend>';
		foreach ( array( 'network' => array( 'Same on every site', 'Only network admins change it, here. Sites cannot override it.' ), 'admins' => array( 'Each site can have its own value, set by network admins', 'Sites inherit the default until a network admin sets one for them.' ), 'all' => array( 'Each site can have its own value, and site admins can edit it', 'Site admins see it under Settings → Brand Identity.' ), 'selected' => array( 'Only selected sites can edit their own value', 'Choose the sites below.' ), 'imported' => array( 'Each site has its own value, from an import', 'Filled in by a spreadsheet import (Import tab). Nobody edits it by hand.' ) ) as $value => $copy ) {
			echo '<label><input type="radio" name="policy" value="' . esc_attr( $value ) . '"' . checked( $value, $policy, false ) . '> <strong>' . esc_html( $copy[0] ) . '</strong><br><span class="description">' . esc_html( $copy[1] ) . '</span></label>';
		}
		echo '</fieldset><div data-show-when="policy=selected">';
		Site_Picker::render( 'sites', 'Sites that can edit this value', $d['sites'] ?? array(), true );
		echo '</div><h3>5. New sites</h3>';
		foreach ( array( 'required' => 'Every site must end up with a value', 'clone' => 'Ask for this when a new site is added, and never copy it from the starting site', 'identifier' => 'Use it to find a site in imports, for example a practice code (text, number, email, phone or link)' ) as $key => $label ) { echo '<p><label><input type="checkbox" name="' . esc_attr( $key ) . '" value="1" ' . checked( ! empty( $d[ $key ] ), true, false ) . '> ' . esc_html( $label ) . '</label></p>'; }
		echo '</div><input type="hidden" name="schema_hash" value="' . esc_attr( Fleet::hash( $defs ) ) . '">'; submit_button( $edit ? 'Save changes' : 'Add variable' ); echo '<a href="' . esc_url( self::url( array( 'tab' => 'definitions' ) ) ) . '">Cancel</a></div></form>';
	}
	/** Holiday closures every site's opening hours respect, optionally only in one segment. */
	private static function closures_panel(): void {
		$closures = Fleet_Hours::closures(); $segments = Fleet_Segments::all();
		echo '<details class="brand-fleet-panel"' . ( isset( $_GET['closures'] ) ? ' open' : '' ) . '><summary>Holiday closures</summary><p class="description">Close every site, or every site in a segment, on a date. Sites with Opening hours variables show it in the Hours block and in search results. A site\'s own line for that date wins. Leave hours empty for closed, or type special hours like <code>9-12</code>.</p>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only state.
		self::begin( 'closures' );
		echo '<table class="widefat brand-fleet-closures"><thead><tr><th scope="col">Date</th><th scope="col">Hours</th><th scope="col">Name</th>' . ( $segments ? '<th scope="col">Only in segment</th>' : '' ) . '</tr></thead><tbody>';
		$rows = $closures; for ( $i = 0; $i < 3; ++$i ) { $rows[] = array( 'date' => '', 'hours' => '', 'label' => '', 'segment' => '' ); }
		foreach ( $rows as $i => $row ) {
			$name = 'closures[' . $i . ']';
			echo '<tr><td><input type="date" name="' . esc_attr( $name . '[date]' ) . '" value="' . esc_attr( $row['date'] ) . '" aria-label="Date"></td><td><input type="text" class="regular-text" name="' . esc_attr( $name . '[hours]' ) . '" value="' . esc_attr( 'closed' === $row['hours'] ? '' : $row['hours'] ) . '" placeholder="Closed" aria-label="Hours"></td><td><input type="text" class="regular-text" maxlength="80" name="' . esc_attr( $name . '[label]' ) . '" value="' . esc_attr( $row['label'] ) . '" placeholder="for example Christmas Day" aria-label="Name"></td>';
			if ( $segments ) { echo '<td><select name="' . esc_attr( $name . '[segment]' ) . '" aria-label="Segment"><option value="">Every site</option>'; foreach ( $segments as $slug => $segment ) { echo '<option value="' . esc_attr( $slug ) . '"' . selected( $slug, $row['segment'], false ) . '>' . esc_html( $segment['label'] ) . '</option>'; } echo '</select></td>'; }
			echo '</tr>';
		}
		echo '</tbody></table><p class="description">Clear a date to remove that closure. Past dates can be removed once they are over.</p>'; submit_button( 'Save holiday closures', 'secondary' ); echo '</form></details>';
	}
	private function import(): void {
		$settings = Fleet_Import::settings(); $matches = Fleet_Import::match_options();
		if ( isset( $_GET['saved'] ) ) { echo '<div class="notice notice-success"><p>Saved.</p></div>'; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice.
		echo '<h2>Import</h2><p class="brand-fleet-intro">Fill in each site\'s values from a spreadsheet: hours, phone numbers, practice codes and so on. The first row holds variable keys, for example <code>practice_code, phone_sales, hours_mon</code>, plus one column that says which site each row is for. Empty cells leave a value unchanged. You always see a preview first.</p>';
		$j = self::current_batch( 'import', 'import' );
		self::begin( 'import' );
		echo '<div class="brand-fleet-definition-form"><h3>1. Spreadsheet</h3><p><label>Link to a CSV (in Google Sheets: File → Share → Publish to web → CSV)<br><input class="large-text" type="url" name="url" placeholder="https://docs.google.com/spreadsheets/d/e/…/pub?output=csv" value="' . esc_attr( $settings['url'] ) . '"></label></p><details class="brand-fleet-panel"><summary>Or paste CSV</summary><p><textarea class="large-text code" rows="6" name="csv" spellcheck="false" aria-label="CSV text, first row is the header" placeholder="site_id,phone_sales&#10;12,555-0100"></textarea></p></details>';
		echo '<h3>2. Match rows to sites by</h3>';
		self::select( 'match', 'Column', $matches, (string) $settings['match'] );
		echo '<p class="description">Mark a variable as an identifier (for example a practice code) under Variables to match by it.</p>';
		submit_button( 'Preview import' ); echo '</div></form>';
		echo '<details class="brand-fleet-panel"><summary>Daily import</summary>';
		self::begin( 'import-settings' );
		echo '<p><label>Spreadsheet link<br><input class="large-text" type="url" name="url" value="' . esc_attr( $settings['url'] ) . '"></label></p>';
		self::select( 'match', 'Match rows by', $matches, (string) $settings['match'] );
		echo '<p><label><input type="checkbox" name="auto" value="1"' . checked( ! empty( $settings['auto'] ), true, false ) . '> Import every day and apply without review</label><br><span class="description">Runs as you. Skipped on days when you have another batch open. Use this when the spreadsheet is the source of truth.</span></p>';
		if ( ! empty( $settings['last'] ) ) { echo '<p class="description">' . esc_html( 'Last run ' . gmdate( 'M j, Y H:i', strtotime( (string) $settings['last']['time'] ) ) . ' UTC: ' . $settings['last']['status'] . ( isset( $settings['last']['error'] ) ? ' — ' . $settings['last']['error'] : '' ) . ( isset( $settings['last']['sites'] ) ? ' (' . $settings['last']['sites'] . ' sites)' : '' ) ) . '</p>'; }
		submit_button( 'Save daily import', 'secondary' ); echo '</form></details>';
		echo '<details class="brand-fleet-panel"' . ( $j ? '' : ' open' ) . '><summary>Download current values</summary><p class="description">Get every fleet site\'s values as a spreadsheet: edit it, then paste or link it above to import the changes back. Columns are variable keys; rows match by <code>site_id</code>.</p><form method="get" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="brand_fleet_export_values">';
		wp_nonce_field( 'brand_fleet_export_values', '_wpnonce', false );
		echo '<fieldset class="brand-fleet-choices"><legend>Which values?</legend>';
		foreach ( Fleet_Export::MODES as $mode => $label ) { echo '<label><input type="radio" name="mode" value="' . esc_attr( $mode ) . '"' . checked( 'own', $mode, false ) . '> ' . esc_html( $label ) . '</label>'; }
		echo '</fieldset>';
		if ( Fleet_Segments::all() ) { self::select( 'segment', 'Sites', array( '' => 'Every site in the fleet' ) + array_map( static fn( $x ) => 'Sites in segment: ' . $x['label'], Fleet_Segments::all() ), '' ); }
		submit_button( 'Download CSV', 'secondary', 'submit', false ); echo '</form></details>';
		if ( ! $j ) { return; }
		$source = (array) ( $j['import'] ?? array() );
		echo '<div id="brand-fleet-current-batch"><h2>' . esc_html( 'Import from ' . ( $source['label'] ?? 'spreadsheet' ) ) . '</h2>';
		self::batch_controls( $j, 'Apply import' );
		if ( ! empty( $source['problems'] ) ) {
			echo '<details class="brand-fleet-panel" open><summary>' . esc_html( sprintf( '%d rows or cells were skipped', (int) $source['problem_count'] ) ) . '</summary><ul>';
			foreach ( $source['problems'] as $problem ) { echo '<li>' . esc_html( $problem ) . '</li>'; }
			echo '</ul>' . ( $source['problem_count'] > count( $source['problems'] ) ? '<p class="description">Only the first 50 are listed.</p>' : '' ) . '</details>';
		}
		self::result_table( $j, 'import' );
		echo '</div>';
	}
	/** Site / status / before / after rows shared by bulk updates and imports. */
	private static function result_table( array $j, string $tab ): void {
		$result_page = max( 1, isset( $_GET['result_page'] ) ? absint( $_GET['result_page'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$counts = Fleet_Jobs::counts( $j ); $defs = Fleet::definitions();
		$show = static function ( array $values ) use ( $defs ): string {
			if ( ! $values ) { return '<span class="description">Shared value</span>'; }
			$out = array();
			foreach ( $values as $key => $value ) { $out[] = '<small>' . esc_html( $defs[ $key ]['label'] ?? $key ) . ':</small> ' . self::preview( $defs[ $key ]['type'] ?? 'text', (string) $value, $defs[ $key ] ?? array() ); }
			return implode( '<br>', $out );
		};
		echo '<p>' . esc_html( implode( ' · ', array_map( static fn( $status, $count ) => ucfirst( $status ) . ': ' . $count, array_keys( $counts ), $counts ) ) ) . '</p><table class="widefat striped"><thead><tr><th>Site</th><th>Status</th><th>Before</th><th>After</th><th>Details</th></tr></thead><tbody>';
		$rows = Fleet_Jobs::rows( $j, ( $result_page - 1 ) * 25, 26 );
		foreach ( array_slice( $rows, 0, 25 ) as $row ) {
			if ( ! isset( $row['status'] ) ) { continue; }
			echo '<tr><td>' . esc_html( Site_Picker::label( (int) $row['site'] ) ) . '</td><td>' . esc_html( ucfirst( $row['status'] ) ) . '</td><td>' . $show( (array) ( $row['before'] ?? array() ) ) . '</td><td>' . $show( (array) ( $row['after'] ?? array() ) ) . '</td><td>' . esc_html( $row['error'] ?? '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- preview() escapes its output.
		}
		echo '</tbody></table><p>';
		if ( $result_page > 1 ) { echo '<a class="button" href="' . esc_url( self::url( array( 'tab' => $tab, 'batch' => $j['id'], 'result_page' => $result_page - 1 ) ) ) . '">Previous results</a> '; }
		if ( count( $rows ) > 25 ) { echo '<a class="button" href="' . esc_url( self::url( array( 'tab' => $tab, 'batch' => $j['id'], 'result_page' => $result_page + 1 ) ) ) . '">Next results</a>'; }
		echo '</p>';
	}
	private function tokens(): void {
		echo '<h2>Token check</h2><p class="brand-fleet-intro">Find <code>{{tokens}}</code> that would show up wrong on a live page: tokens with no matching variable print as raw text, and tokens whose value is empty print nothing. Run it after converting old shortcodes to tokens, or before launching sites. It only reads content.</p>';
		$j = self::current_batch( 'report', 'tokens' );
		self::begin( 'tokens' );
		echo '<div class="brand-fleet-definition-form"><h3>1. Which sites?</h3>';
		self::scope_picker();
		echo '<fieldset class="brand-fleet-choices"><legend>Content</legend>';
		foreach ( Fleet_Report::TYPES as $type => $label ) { echo '<label><input type="checkbox" name="types[]" value="' . esc_attr( $type ) . '" checked> ' . esc_html( $label ) . '</label>'; }
		echo '</fieldset>'; submit_button( 'Check tokens' ); echo '</div></form>';
		if ( ! $j ) { return; }
		echo '<div id="brand-fleet-current-batch"><h2>Results</h2>';
		self::batch_controls( $j, '' );
		echo '<p>' . esc_html( sprintf( _n( '%1$d page with problems on %2$d of %3$d sites checked.', '%1$d pages with problems on %2$d of %3$d sites checked.', (int) ( $j['matched'] ?? 0 ), 'brand-fleet' ), (int) ( $j['matched'] ?? 0 ), count( $j['hits'] ?? array() ), (int) $j['cursor'] ) ) . '</p>';
		$result_page = max( 1, isset( $_GET['result_page'] ) ? absint( $_GET['result_page'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rows = Fleet_Jobs::hit_rows( $j, ( $result_page - 1 ) * 25, 25 );
		if ( $rows ) {
			echo '<table class="widefat brand-fleet-matches"><thead><tr><th scope="col">Page</th><th scope="col">Prints as raw text (no such variable)</th><th scope="col">Prints nothing (empty value)</th></tr></thead>';
			foreach ( $rows as $row ) {
				echo '<tbody><tr class="brand-fleet-group"><th colspan="3" scope="colgroup">' . esc_html( Site_Picker::label( (int) $row['site'] ) ) . ( empty( $row['enrolled'] ) ? ' <span class="brand-fleet-tag">Not in the fleet</span>' : '' ) . ( ! empty( $row['more'] ) ? ' <span class="brand-fleet-tag">' . esc_html( sprintf( '+%d more pages', (int) $row['more'] ) ) . '</span>' : '' ) . '</th></tr>';
				foreach ( (array) $row['matches'] as $m ) {
					echo '<tr><td><a href="' . esc_url( get_admin_url( (int) $row['site'], 'post.php?post=' . (int) $m['post'] . '&action=edit' ) ) . '" target="_blank" rel="noopener">' . esc_html( '' !== $m['title'] ? $m['title'] : '(no title)' ) . '</a><br><small>' . esc_html( $m['type'] . ( 'publish' === $m['status'] ? '' : ' · ' . $m['status'] ) ) . '</small></td><td>' . self::codes( $m['unknown'] ) . '</td><td>' . self::codes( $m['empty'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- codes() escapes each item.
				}
				echo '</tbody>';
			}
			echo '</table><p>';
			if ( $result_page > 1 ) { echo '<a class="button" href="' . esc_url( self::url( array( 'tab' => 'tokens', 'batch' => $j['id'], 'result_page' => $result_page - 1 ) ) ) . '">Previous sites</a> '; }
			if ( count( $j['hits'] ?? array() ) > $result_page * 25 ) { echo '<a class="button" href="' . esc_url( self::url( array( 'tab' => 'tokens', 'batch' => $j['id'], 'result_page' => $result_page + 1 ) ) ) . '">Next sites</a>'; }
			echo '</p>';
		} elseif ( 'complete' === $j['phase'] ) { echo '<p>No problems found: every token on these sites has a value.</p>'; }
		echo '</div>';
	}
	private static function codes( array $items ): string { return implode( ' ', array_map( static fn( $item ) => '<code>' . esc_html( (string) $item ) . '</code>', $items ) ); }
	private function segments(): void {
		if ( isset( $_GET['saved'] ) ) { echo '<div class="notice notice-success"><p>Saved.</p></div>'; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice.
		echo '<h2>Segments</h2><p class="brand-fleet-intro">A segment is a group of sites that share a dropdown or yes / no value, for example every site where Province is Quebec, or every French site. Use segments to target bulk updates, find &amp; replace and embeds, to give variables a different default per segment, and to pick regional shared pages (a shared content slug like <code>privacy-{{province}}</code>).</p>';
		$keys = Fleet_Segments::rule_keys(); $defs = Fleet::definitions();
		if ( ! $keys ) { echo '<p>First add a Dropdown or Yes / No variable, such as Province or Language, under Variables. Segments are built from those.</p>'; return; }
		if ( Fleet_Segments::pending() ) { echo '<p class="brand-fleet-note">Segment membership is being refreshed in the background. Targeting still works; it is slower until the refresh finishes.</p>'; }
		self::begin( 'segments' );
		echo '<input type="hidden" name="segments_hash" value="' . esc_attr( Fleet::hash( Fleet_Segments::all() ) ) . '">';
		$rows = array_values( array_map( static fn( $slug, $x ) => $x + array( 'slug' => $slug ), array_keys( Fleet_Segments::all() ), Fleet_Segments::all() ) );
		$rows[] = array( 'slug' => '', 'label' => '', 'rules' => array() );
		foreach ( $rows as $i => $row ) {
			$new = '' === $row['slug'];
			echo '<fieldset class="brand-fleet-panel brand-fleet-segment"><legend><strong>' . esc_html( $new ? 'Add a segment' : $row['label'] ) . '</strong>' . ( $new ? '' : ' <span class="description">' . esc_html( sprintf( _n( '%d site', '%d sites', count( Fleet_Segments::sites( $row['slug'] ) ), 'brand-fleet' ), count( Fleet_Segments::sites( $row['slug'] ) ) ) ) . '</span>' ) . '</legend>';
			echo '<p class="brand-fleet-row"><label>Name<br><input class="regular-text" type="text" name="segments[' . esc_attr( (string) $i ) . '][label]" value="' . esc_attr( $row['label'] ) . '" placeholder="for example Quebec sites"></label> ';
			echo '<label>Key<br><input class="regular-text code" type="text" pattern="[a-z][a-z0-9_\-]{0,39}" name="segments[' . esc_attr( (string) $i ) . '][slug]" value="' . esc_attr( $row['slug'] ) . '"' . ( $new ? '' : ' readonly' ) . ' placeholder="quebec"></label> ';
			echo '<label>Order<br><input class="small-text" type="number" name="segments[' . esc_attr( (string) $i ) . '][order]" value="' . esc_attr( (string) ( $i + 1 ) ) . '"></label></p><p class="description">Sites match when they have <strong>any</strong> checked value for <strong>every</strong> variable you check something for.</p>';
			foreach ( $keys as $key ) {
				$choices = 'toggle' === $defs[ $key ]['type'] ? array( '1' => $defs[ $key ]['options'][0] ?? 'Yes', '0' => $defs[ $key ]['options'][1] ?? 'No' ) : array_combine( $defs[ $key ]['options'], $defs[ $key ]['options'] );
				echo '<p><span class="brand-fleet-field-label">' . esc_html( $defs[ $key ]['label'] ) . '</span> ';
				foreach ( $choices as $value => $text ) { echo '<label class="brand-fleet-inline"><input type="checkbox" name="segments[' . esc_attr( (string) $i ) . '][rules][' . esc_attr( $key ) . '][]" value="' . esc_attr( (string) $value ) . '"' . checked( in_array( (string) $value, (array) ( $row['rules'][ $key ] ?? array() ), true ), true, false ) . '> ' . esc_html( (string) $text ) . '</label> '; }
				echo '</p>';
			}
			if ( ! $new ) { echo '<p><label><input type="checkbox" name="segments[' . esc_attr( (string) $i ) . '][remove]" value="1"> Remove this segment</label></p>'; }
			echo '</fieldset>';
		}
		submit_button( 'Save segments' ); echo '</form>';
	}
	/** "Undo" for an applied batch, while it can still be undone. */
	private static function undo_button( string $batch ): void {
		if ( ! Fleet::can( 'bulk' ) ) { return; }
		$e = Fleet_Undo::entry( $batch );
		if ( ! $e ) { return; }
		if ( ! empty( $e['undone'] ) ) { echo '<p class="description">Undone.</p>'; return; }
		self::begin( 'undo' );
		echo '<input type="hidden" name="batch" value="' . esc_attr( $batch ) . '"><p class="brand-fleet-actions"><button class="button">' . esc_html( sprintf( _n( 'Undo on %d site…', 'Undo on %d sites…', (int) $e['sites'], 'brand-fleet' ), (int) $e['sites'] ) ) . '</button> <span class="description">You see what would change first.</span></p></form>';
	}
	/** Preview and progress of an undo. */
	private function undo_view( array $j ): void {
		echo '<p><a href="' . esc_url( self::url( array( 'tab' => 'audit' ) ) ) . '">← Activity</a></p><h2>' . esc_html( Fleet_Undo::label( $j ) ) . '</h2><p class="brand-fleet-intro">This puts back what that batch changed. Sites or pages edited since then are skipped and listed, never overwritten.</p>';
		self::batch_controls( $j, 'Undo on these sites' );
		if ( 'undo' === $j['kind'] ) { self::result_table( $j, 'audit' ); return; }
		$rows = Fleet_Jobs::hit_rows( $j, 0, 100 );
		echo '<table class="widefat brand-fleet-matches"><thead><tr><th scope="col">Page</th><th scope="col">Result</th></tr></thead>';
		foreach ( $rows as $row ) {
			echo '<tbody><tr class="brand-fleet-group"><th colspan="2" scope="colgroup">' . esc_html( Site_Picker::label( (int) $row['site'] ) ) . '</th></tr>';
			foreach ( (array) $row['matches'] as $m ) { echo '<tr><td><a href="' . esc_url( get_admin_url( (int) $row['site'], 'post.php?post=' . (int) $m['post'] . '&action=edit' ) ) . '" target="_blank" rel="noopener">' . esc_html( '' !== $m['title'] ? $m['title'] : '(no title)' ) . '</a></td><td>' . esc_html( ucfirst( $m['result'] ?? ( $m['changed'] ? 'Edited since, will be left as is' : 'Will be restored' ) ) ) . '</td></tr>'; }
			echo '</tbody>';
		}
		echo '</table>';
	}
	/** Change requests from sites, oldest first, with approve / decline. */
	private function requests(): void {
		$decided = isset( $_GET['decided'] ) ? sanitize_key( wp_unslash( $_GET['decided'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice.
		if ( $decided ) { echo '<div class="notice notice-success"><p>' . esc_html( 'approved' === $decided ? 'Approved. The site now shows the new value.' : 'Declined. The person who asked gets an email.' ) . '</p></div>'; }
		$defs = Fleet::definitions(); $pending = Fleet_Requests::pending();
		echo '<h2>Requests</h2><p class="brand-fleet-intro">Site admins can ask for a new value for something the network controls, such as their phone number or hours, from Settings → Brand Identity. Approving sets it on that site right away, and it shows in the site\'s value history.</p>';
		if ( ! $pending ) { echo '<p>No open requests.</p>'; }
		foreach ( $pending as $r ) {
			$d = $defs[ $r['key'] ] ?? array( 'label' => $r['key'], 'type' => 'text' );
			$user = get_userdata( (int) $r['user'] );
			echo '<div class="brand-fleet-panel brand-fleet-request"><h3>' . esc_html( $d['label'] . ' — ' . Site_Picker::label( (int) $r['site'] ) ) . '</h3><p class="description">' . esc_html( 'Asked by ' . ( $user ? $user->display_name : 'a deleted user' ) . ' on ' . gmdate( 'M j, Y H:i', strtotime( $r['time'] ) ) . ' UTC' ) . '</p>';
			echo '<table class="widefat"><tbody><tr><th scope="row">Now</th><td>' . self::preview( $d['type'], (string) Fleet::resolved( (int) $r['site'], $r['key'] )['value'], $d ) . '</td></tr><tr><th scope="row">Asked for</th><td>' . self::preview( $d['type'], (string) $r['value'], $d ) . '</td></tr>' . ( '' !== $r['note'] ? '<tr><th scope="row">Note</th><td>' . nl2br( esc_html( $r['note'] ) ) . '</td></tr>' : '' ) . '</tbody></table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- preview() escapes its output.
			self::begin( 'decide' );
			echo '<input type="hidden" name="request" value="' . esc_attr( $r['id'] ) . '"><p><label>Note to the person who asked (optional)<br><input class="large-text" type="text" name="reason" maxlength="500"></label></p><p class="brand-fleet-actions"><button class="button button-primary" name="decision" value="approve">Approve</button> <button class="button" name="decision" value="decline">Decline</button></p></form></div>';
		}
		$done = array_slice( array_reverse( array_values( array_filter( Fleet_Requests::all(), static fn( $r ) => in_array( $r['status'], array( 'approved', 'declined' ), true ) ) ) ), 0, 20 );
		if ( $done ) {
			echo '<h3>Recently answered</h3><table class="widefat striped"><thead><tr><th scope="col">Site</th><th scope="col">Variable</th><th scope="col">Answer</th><th scope="col">By</th></tr></thead><tbody>';
			foreach ( $done as $r ) {
				$by = get_userdata( (int) $r['decided_by'] );
				echo '<tr><td>' . esc_html( Site_Picker::label( (int) $r['site'] ) ) . '</td><td>' . esc_html( $defs[ $r['key'] ]['label'] ?? $r['key'] ) . '</td><td>' . esc_html( ucfirst( $r['status'] ) . ( '' !== $r['reason'] ? ' — ' . $r['reason'] : '' ) ) . '</td><td>' . esc_html( ( $by ? $by->display_name : '—' ) . ', ' . gmdate( 'M j', strtotime( $r['decided_at'] ) ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}
	/** Site admins: ask the network for a value they cannot edit, and see earlier answers. */
	private static function request_form( int $site ): void {
		$defs = Fleet_Requests::requestable( $site );
		if ( ! $defs || ! Fleet::profile( $site ) ) { return; }
		echo '<h2 id="request">Ask for a change</h2><p>Some values are set by your network. If one is wrong for this site, ask for a new value and a fleet editor will review it.</p>';
		if ( isset( $_GET['requested'] ) ) { echo '<div class="notice notice-success inline"><p>Sent. You will get an email when it is answered.</p></div>'; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice.
		self::begin( 'request' );
		echo '<input type="hidden" name="site_id" value="' . esc_attr( (string) $site ) . '"><p><label>Value<br><select name="key">';
		foreach ( $defs as $key => $d ) { echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $d['label'] ) . '</option>'; }
		echo '</select></label></p><div class="brand-fleet-field"><span class="brand-fleet-field-label">New value</span>';
		self::typed_inputs( 'key', 'value', array_map( static fn( $d ) => array( $d['type'], $d['options'] ?? array() ), $defs ), (string) array_key_first( $defs ), '', array( 'aria-label' => 'New value' ) );
		echo '</div><p><label>Why (optional)<br><textarea class="large-text" rows="2" name="note" maxlength="500"></textarea></label></p>'; submit_button( 'Send request', 'secondary' ); echo '</form>';
		$mine = Fleet_Requests::for_site( $site );
		if ( $mine ) {
			$states = array( 'pending' => 'waiting for review', 'approved' => 'approved', 'declined' => 'declined', 'replaced' => 'replaced by a newer request' );
			echo '<h3>Earlier requests</h3><ul>';
			foreach ( $mine as $r ) { echo '<li>' . esc_html( ( Fleet::definitions()[ $r['key'] ]['label'] ?? $r['key'] ) . ': ' . ( $states[ $r['status'] ] ?? $r['status'] ) . ( ! empty( $r['reason'] ) ? ' — ' . $r['reason'] : '' ) ) . '</li>'; }
			echo '</ul>';
		}
	}
	/** Which sites set their own value instead of the shared one, per variable, with a reviewed way to reset them. */
	private function overrides(): void {
		Fleet_Overrides::backfill();
		$key = isset( $_GET['key'] ) ? sanitize_key( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$vars = Fleet_Overrides::variables(); $fleet = Fleet_Overrides::fleet_count();
		if ( ! Fleet_Overrides::indexed() ) { echo '<p class="brand-fleet-note">Counting existing sites in the background. Numbers may be low until it finishes; reload in a minute.</p>'; }
		if ( '' === $key || ! isset( $vars[ $key ] ) ) {
			echo '<h2>Overrides</h2><p class="brand-fleet-intro">See where sites have set their own value instead of using the shared one. A lot of sites overriding the same thing can mean the shared value is wrong, or that a value has drifted from brand. Imported variables show how many sites the spreadsheet has filled in.</p>';
			echo '<table class="widefat striped"><thead><tr><th scope="col">Variable</th><th scope="col">Shared value</th><th scope="col">Sites with their own value</th><th scope="col"><span class="screen-reader-text">Details</span></th></tr></thead><tbody>';
			foreach ( self::grouped( $vars ) as $group_defs ) {
				foreach ( $group_defs as $k => $d ) {
					$count = Fleet_Overrides::count( $k );
					echo '<tr><td><strong>' . esc_html( $d['label'] ) . '</strong><br>' . self::token( $k ) . '</td><td>' . ( 'imported' === $d['scope'] ? '<span class="description">From an import</span>' : self::preview( $d['type'], (string) $d['default'], $d ) ) . '</td><td>' . esc_html( sprintf( '%d of %d', $count, $fleet ) ) . ( $fleet ? '<span class="brand-fleet-meter" aria-hidden="true"><span style="width:' . esc_attr( (string) round( 100 * $count / $fleet ) ) . '%"></span></span>' : '' ) . '</td><td>' . ( $count ? '<a href="' . esc_url( self::url( array( 'tab' => 'overrides', 'key' => $k ) ) ) . '">View sites</a>' : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- token() and preview() escape their output.
				}
			}
			if ( ! $vars ) { echo '<tr><td colspan="4">No variables can be set per site yet.</td></tr>'; }
			echo '</tbody></table>';
			return;
		}
		$d = $vars[ $key ]; $page = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ids = Fleet_Overrides::sites( $key, $page ); $count = Fleet_Overrides::count( $key );
		echo '<p><a href="' . esc_url( self::url( array( 'tab' => 'overrides' ) ) ) . '">← All variables</a></p><h2>' . esc_html( $d['label'] . ': ' . sprintf( _n( '%d site with its own value', '%d sites with their own value', $count, 'brand-fleet' ), $count ) ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th scope="col">Site</th><th scope="col">Its own value</th><th scope="col">Shared value it would use</th></tr></thead><tbody>';
		foreach ( array_slice( $ids, 0, 25 ) as $id ) {
			$own = (string) ( Fleet::profile( $id )['values'][ $key ] ?? '' );
			$shared = 'imported' === $d['scope'] ? null : self::shared_value( $id, $key );
			echo '<tr><td><a href="' . esc_url( self::url( array( 'site_id' => $id ) ) ) . '">' . esc_html( Site_Picker::label( $id ) ) . '</a></td><td>' . self::preview( $d['type'], $own, $d ) . ( null !== $shared && $shared === $own ? ' <span class="brand-fleet-tag">Same as shared</span>' : '' ) . '</td><td>' . ( null === $shared ? '<span class="description">—</span>' : self::preview( $d['type'], $shared, $d ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- preview() escapes its output.
		}
		echo '</tbody></table><p>';
		if ( $page > 1 ) { echo '<a class="button" href="' . esc_url( self::url( array( 'tab' => 'overrides', 'key' => $key, 'paged' => $page - 1 ) ) ) . '">Previous</a> '; }
		if ( count( $ids ) > 25 ) { echo '<a class="button" href="' . esc_url( self::url( array( 'tab' => 'overrides', 'key' => $key, 'paged' => $page + 1 ) ) ) . '">Next</a>'; }
		echo '</p>';
		if ( 'location' === $d['scope'] && Fleet::can( 'bulk' ) ) {
			self::begin( 'reset-overrides' );
			echo '<input type="hidden" name="key" value="' . esc_attr( $key ) . '"><p>Put all of these sites back on the shared value. You see a preview first, and the change can be undone from Activity.</p>'; submit_button( sprintf( 'Preview reset of %d sites', $count ), 'secondary' ); echo '</form>';
		}
	}
	/** The value a site would show without its own: its main data site, a segment default or the network default. */
	private static function shared_value( int $site, string $key ): string {
		$p = Fleet::profile( $site ); unset( $p['values'][ $key ] );
		$source = (int) ( $p['source_id'] ?? 0 );
		if ( $source && array_key_exists( $key, Fleet::profile( $source )['values'] ?? array() ) ) { return (string) Fleet::profile( $source )['values'][ $key ]; }
		$d = Fleet::definitions()[ $key ];
		foreach ( array_keys( Fleet_Segments::all() ) as $slug ) { if ( array_key_exists( $slug, (array) ( $d['segment_defaults'] ?? array() ) ) && Fleet_Segments::matches( $site, $slug ) ) { return (string) $d['segment_defaults'][ $slug ]; } }
		return (string) $d['default'];
	}
	/** Local SEO: map variables to schema.org LocalBusiness fields, with a live preview for one site. */
	private function seo(): void {
		if ( isset( $_GET['saved'] ) ) { echo '<div class="notice notice-success"><p>Saved.</p></div>'; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice.
		$settings = Fleet_Schema::settings(); $defs = Fleet::definitions();
		echo '<h2>Local SEO</h2><p class="brand-fleet-intro">Tell search engines about each location: its name, address, phone, opening hours and holiday closures. Every site in the fleet gets its own business details (schema.org <code>LocalBusiness</code>) from its own variables, which helps it show up in local and map results. Nothing is added for visitors to download.</p>';
		self::begin( 'local-seo' );
		echo '<div class="brand-fleet-definition-form"><p><label><input type="checkbox" name="enabled" value="1"' . checked( ! empty( $settings['enabled'] ), true, false ) . '> <strong>Add business details to fleet sites</strong></label><br><span class="description">Turn this off if another plugin already adds local business details, so search engines do not see them twice.</span></p>';
		self::select( 'type', 'Kind of business', Fleet_Schema::TYPES, (string) $settings['type'] );
		echo '<fieldset class="brand-fleet-choices"><legend>Where</legend><label><input type="radio" name="where" value="home"' . checked( 'home', $settings['where'], false ) . '> Home page only (recommended)</label><label><input type="radio" name="where" value="all"' . checked( 'all', $settings['where'], false ) . '> Every page</label></fieldset>';
		echo '<h3>Which variable holds each detail?</h3><table class="form-table"><tbody>';
		foreach ( Fleet_Schema::FIELDS as $field => $spec ) {
			$options = array( '' => '— Not used —' );
			foreach ( $defs as $key => $d ) { if ( in_array( $d['type'], $spec[1], true ) ) { $options[ $key ] = $d['label'] . ' ({{' . $key . '}})'; } }
			echo '<tr><th scope="row"><label for="brand-fleet-seo-' . esc_attr( $field ) . '">' . esc_html( $spec[0] ) . '</label></th><td><select id="brand-fleet-seo-' . esc_attr( $field ) . '" name="map[' . esc_attr( $field ) . ']">';
			foreach ( $options as $key => $label ) { echo '<option value="' . esc_attr( (string) $key ) . '"' . selected( (string) $key, (string) ( $settings['map'][ $field ] ?? '' ), false ) . '>' . esc_html( $label ) . '</option>'; }
			echo '</select>' . ( 'hours' === $field && count( $options ) < 2 ? ' <span class="description">Add an Opening hours variable under Variables to include hours.</span>' : '' ) . ( 'street' === $field ? ' <span class="description">A one-field address works too: choose it here and leave the rest empty.</span>' : '' ) . '</td></tr>';
		}
		$links = array_filter( $defs, static fn( $d ) => 'url' === $d['type'] );
		if ( $links ) {
			echo '<tr><th scope="row">Social profiles</th><td><fieldset><legend class="screen-reader-text">Social profiles</legend>';
			foreach ( $links as $key => $d ) { echo '<label class="brand-fleet-inline"><input type="checkbox" name="same_as[]" value="' . esc_attr( $key ) . '"' . checked( in_array( $key, (array) $settings['same_as'], true ), true, false ) . '> ' . esc_html( $d['label'] ) . '</label> '; }
			echo '</fieldset></td></tr>';
		}
		echo '</tbody></table>'; submit_button( 'Save local SEO' ); echo '</div></form>';
		$preview = isset( $_GET['preview_site'] ) ? absint( $_GET['preview_site'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preview.
		echo '<h3>Preview for one site</h3><form method="get" class="brand-fleet-search-form"><input type="hidden" name="page" value="brand-fleet"><input type="hidden" name="tab" value="seo">';
		Site_Picker::render( 'preview_site', 'Site', array( $preview ), false );
		echo '<button class="button">Show preview</button></form>';
		if ( $preview && Fleet::site( $preview ) ) {
			$data = Fleet_Schema::data( $preview, array( 'enabled' => true ) + $settings );
			if ( ! $data ) { echo '<p>' . esc_html( Fleet::profile( $preview ) ? 'This site has no business name yet, so nothing would be added.' : 'This site is not in the fleet yet.' ) . '</p>'; }
			else { echo '<pre class="brand-fleet-json">' . esc_html( (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</pre><p><a href="' . esc_url( 'https://search.google.com/test/rich-results?url=' . rawurlencode( get_home_url( $preview ) ) ) . '" target="_blank" rel="noopener">Test this site with Google\'s Rich Results Test</a> (works once the site is public).</p>'; }
		}
	}
	/** Fleet capabilities per main-site role, so central editors can work without being super admins. */
	private function access(): void {
		if ( isset( $_GET['saved'] ) ) { echo '<div class="notice notice-success"><p>Saved.</p></div>'; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice.
		$labels = array( 'manage' => 'Manage variables, segments and connections', 'bulk' => 'Run bulk updates, find & replace, imports and token checks', 'values' => 'Edit any site\'s values' );
		echo '<h2>Access</h2><p class="brand-fleet-intro">Network admins can do everything. Give other people fleet access by role on the main site (' . esc_html( Site_Picker::label( (int) get_main_site_id() ) ) . '). They find Brand Fleet in the main site\'s dashboard. Managing variables includes the other two. Embed code always needs a network admin.</p>';
		self::begin( 'access' );
		echo '<table class="widefat striped"><thead><tr><th scope="col">Main-site role</th>';
		foreach ( $labels as $label ) { echo '<th scope="col">' . esc_html( $label ) . '</th>'; }
		echo '</tr></thead><tbody>';
		foreach ( self::main_roles() as $role => $info ) {
			echo '<tr><th scope="row">' . esc_html( translate_user_role( $info['name'] ) ) . '</th>';
			foreach ( array_keys( $labels ) as $what ) { echo '<td><input type="checkbox" aria-label="' . esc_attr( $info['name'] . ': ' . $labels[ $what ] ) . '" name="caps[' . esc_attr( $role ) . '][]" value="' . esc_attr( $what ) . '"' . checked( ! empty( $info['capabilities'][ Fleet::CAPS[ $what ] ] ), true, false ) . '></td>'; }
			echo '</tr>';
		}
		echo '</tbody></table>'; submit_button( 'Save access' ); echo '</form>';
		$alerts = Fleet_Alerts::settings();
		echo '<h2 id="alerts">Email alerts</h2><p class="brand-fleet-intro">Get an email when something needs a person. Each alert is sent at most once an hour.</p>';
		self::begin( 'alerts' );
		echo '<p><label>Send alerts to (up to 10 addresses, separated by commas)<br><input class="large-text" type="text" name="emails" value="' . esc_attr( implode( ', ', (array) $alerts['emails'] ) ) . '"></label></p><fieldset class="brand-fleet-choices"><legend>Email when</legend>';
		foreach ( Fleet_Alerts::EVENTS as $event => $label ) { echo '<label><input type="checkbox" name="events[]" value="' . esc_attr( $event ) . '"' . checked( in_array( $event, (array) $alerts['events'], true ), true, false ) . '> ' . esc_html( $label ) . '</label>'; }
		echo '</fieldset><p class="description">The person who started a batch or set up the daily import is always included for their own alerts.</p>'; submit_button( 'Save email alerts', 'secondary' ); echo '</form>';
	}
	private static function main_roles(): array {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- Roles live per site; read the main site's, restored right after.
		switch_to_blog( (int) get_main_site_id() );
		try { return wp_roles()->roles; } finally { restore_current_blog(); }
	}
	private static function save_access( array $caps ): void {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- Role capabilities are stored on the main site; restored in finally.
		switch_to_blog( (int) get_main_site_id() );
		try {
			foreach ( array_keys( wp_roles()->roles ) as $role ) {
				$object = get_role( $role ); $want = array_map( 'sanitize_key', (array) ( $caps[ $role ] ?? array() ) );
				foreach ( Fleet::CAPS as $what => $cap ) { if ( in_array( $what, $want, true ) ) { $object->add_cap( $cap ); } else { $object->remove_cap( $cap ); } }
			}
		} finally { restore_current_blog(); }
		Fleet::audit( 'access', 0, array() );
	}
	private function sites(): void {
		$id = isset( $_GET['site_id'] ) ? absint( $_GET['site_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $id && Fleet::site( $id ) ) {
			$p = Fleet::profile( $id );
			echo '<p><a href="' . esc_url( self::url() ) . '">← All sites</a></p><h2>' . esc_html( Site_Picker::label( $id ) ) . '</h2>';
			if ( isset( $_GET['restored'] ) ) { echo '<div class="notice notice-success inline"><p>Value restored.</p></div>'; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice.
			if ( isset( $_GET['restored'] ) ) { echo '<div class="notice notice-success inline"><p>Value restored.</p></div>'; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice.
			if ( ! $p ) { echo '<div class="notice notice-info inline"><p>This site is not in the fleet yet. Saving enrolls it: it starts using the shared values below.</p></div>'; }
			self::begin( 'site' );
			echo '<input type="hidden" name="site_id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="connections" value="1"><details class="brand-fleet-panel"' . ( $p ? '' : ' open' ) . '><summary>Connections</summary><p class="description">A main data site lets this site reuse another site\'s values. A brand hub groups sites for bulk updates.</p>';
			foreach ( array( 'group_id' => 'Brand hub', 'source_id' => 'Main data site (inherit values from)', 'template_id' => 'Starting template (for reference)' ) as $field => $label ) { Site_Picker::render( $field, $label, array( $p[ $field ] ?? 0 ), false, 'source_id' === $field ? 'Network defaults' : 'None', 'source_id' === $field ? $id : 0 ); }
			echo '</details>';
			self::values( $id ); submit_button( $p ? 'Save site' : 'Add to fleet and save' ); echo '</form>';
			self::history( $id ); return;
		}
		$q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<h2>Sites</h2><p class="brand-fleet-intro">Each site shows the network\'s shared values unless it has its own. Order of precedence: the site\'s own value, then its main data site, then the network default. Open a site to set its values or add it to the fleet.</p><form class="brand-fleet-search-form"><input type="hidden" name="page" value="brand-fleet"><label>Search domain or path <input type="search" name="q" value="' . esc_attr( $q ) . '"></label><button class="button">Search</button></form><table class="widefat striped"><thead><tr><th>Site</th><th>Brand hub</th><th>Main data site</th><th>Status</th></tr></thead><tbody>';
		$sites = Fleet::listed_sites( $q, $page );
		foreach ( array_slice( $sites, 0, 25 ) as $site ) { $p = Fleet::profile( (int) $site->blog_id ); echo '<tr><td><a href="' . esc_url( self::url( array( 'site_id' => $site->blog_id ) ) ) . '">' . esc_html( Site_Picker::label( (int) $site->blog_id ) ) . '</a></td><td>' . esc_html( empty( $p['group_id'] ) ? '—' : Site_Picker::label( (int) $p['group_id'] ) ) . '</td><td>' . esc_html( empty( $p['source_id'] ) ? 'Network defaults' : Site_Picker::label( (int) $p['source_id'] ) ) . '</td><td>' . ( $p ? '<span class="brand-fleet-tag is-on">In fleet</span>' : '<span class="brand-fleet-tag">Not enrolled</span>' ) . '</td></tr>'; }
		echo '</tbody></table><p>';
		if ( $page > 1 ) { echo '<a class="button" href="' . esc_url( self::url( array( 'paged' => $page - 1, 'q' => $q ) ) ) . '">Previous</a> '; }
		if ( count( $sites ) > 25 ) { echo '<a class="button" href="' . esc_url( self::url( array( 'paged' => $page + 1, 'q' => $q ) ) ) . '">Next</a>'; } echo '</p>';
	}
	/** Every enrolled site, a hub's sites, or the chosen sites. */
	private static function target_ids( array $in ): array {
		$base = array( 'network_id' => get_current_network_id(), 'number' => 10001, 'fields' => 'ids', 'deleted' => 0, 'archived' => 0, 'spam' => 0 );
		$scope = sanitize_key( $in['scope'] ?? '' );
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed managed-key existence; no unindexed meta_value comparison.
		if ( 'all' === $scope ) { return get_sites( $base + array( 'meta_query' => array( array( 'key' => Fleet::MANAGED, 'compare' => 'EXISTS' ) ) ) ); }
		if ( 'segment' === $scope ) { return Fleet_Segments::sites( sanitize_key( $in['segment'] ?? '' ) ); }
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Exact relationship-key lookup uses the core blogmeta meta_key index; no meta_value comparison.
		if ( ! empty( $in['group_id'] ) && 'chosen' !== $scope ) { return get_sites( $base + array( 'meta_key' => 'brand_fleet_group_' . absint( $in['group_id'] ) ) ); }
		return is_array( $in['ids'] ?? null ) ? $in['ids'] : preg_split( '/[\s,]+/', (string) ( $in['ids'] ?? '' ) );
	}
	private static function scope_picker(): void {
		echo '<fieldset class="brand-fleet-choices"><legend>Which sites?</legend>';
		$options = array( 'all' => 'Every site in the fleet', 'hub' => 'All sites in a brand hub', 'chosen' => 'Sites I choose' );
		if ( Fleet_Segments::all() ) { $options = array( 'all' => $options['all'], 'segment' => 'Sites in a segment (for example a province or language)' ) + $options; }
		foreach ( $options as $value => $label ) { echo '<label><input type="radio" name="scope" value="' . esc_attr( $value ) . '"' . checked( 'all', $value, false ) . '> ' . esc_html( $label ) . '</label>'; }
		echo '</fieldset>';
		if ( Fleet_Segments::all() ) { echo '<div data-show-when="scope=segment">'; self::select( 'segment', 'Segment', array_map( static fn( $x ) => $x['label'], Fleet_Segments::all() ), '' ); echo '</div>'; }
		echo '<div data-show-when="scope=hub">';
		Site_Picker::render( 'group_id', 'Brand hub', array(), false );
		echo '</div><div data-show-when="scope=chosen">';
		Site_Picker::render( 'ids', 'Sites', array(), true );
		echo '</div>';
	}
	private const PHASES = array( 'preview' => 'Preparing preview', 'ready' => 'Preview ready — review, then apply', 'apply' => 'Applying', 'complete' => 'Done', 'cancelled' => 'Cancelled', 'stopped' => 'Stopped' );
	/** Progress line, background note and apply / cancel buttons shared by bulk updates and find and replace. */
	private static function batch_controls( array $j, string $apply_label ): void {
		echo '<p id="brand-fleet-batch-status" class="brand-fleet-status">' . esc_html( ( self::PHASES[ $j['phase'] ] ?? $j['phase'] ) . ' · ' . $j['cursor'] . '/' . count( $j['ids'] ) . ' sites' ) . '</p>';
		if ( ! empty( $j['error'] ) ) { echo '<div class="notice notice-error inline"><p>' . esc_html( $j['error'] ) . '</p></div>'; }
		if ( in_array( $j['phase'], array( 'preview', 'apply' ), true ) ) { echo '<p class="description">This keeps running in the background. You can close this page and come back.</p>'; }
		wp_enqueue_script( 'brand-fleet-batch', BRAND_FLEET_URL . 'assets/fleet-batch.js', array(), BRAND_FLEET_VERSION, true );
		if ( in_array( $j['phase'], array( 'complete', 'cancelled', 'stopped' ), true ) ) { self::undo_button( $j['id'] ); }
		if ( in_array( $j['phase'], array( 'preview', 'ready', 'apply' ), true ) ) {
			echo '<p class="brand-fleet-actions">';
			if ( 'ready' === $j['phase'] ) { echo '<button type="button" class="button button-primary" id="brand-fleet-batch-run">' . esc_html( $apply_label ) . '</button> '; }
			echo '<button type="button" class="button" id="brand-fleet-batch-cancel">' . ( 'apply' === $j['phase'] ? 'Stop' : 'Discard' ) . '</button></p>';
			wp_localize_script( 'brand-fleet-batch', 'brandFleetBatch', array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'brand_fleet_batch' ), 'id' => $j['id'], 'cursor' => $j['cursor'], 'phase' => $j['phase'], 'labels' => self::PHASES ) );
		}
	}
	/** The current batch when it is of $kind and the URL asks for it; otherwise a pointer to it. */
	private static function current_batch( string $kind, string $tab ): array {
		$j = Fleet_Jobs::get();
		if ( ! $j ) { return array(); }
		$batch = isset( $_GET['batch'] ) ? sanitize_text_field( wp_unslash( $_GET['batch'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only batch view.
		$mine = ( $j['kind'] ?? 'bulk' ) === $kind;
		if ( $batch === $j['id'] && $mine ) { return $j; }
		$running = in_array( $j['phase'], array( 'preview', 'ready', 'apply' ), true );
		$kinds = array( 'bulk' => array( 'bulk', 'bulk update' ), 'replace' => array( 'replace', 'find and replace' ), 'report' => array( 'tokens', 'token check' ), 'import' => array( 'import', 'import' ), 'undo' => array( 'audit', 'undo' ), 'revert' => array( 'audit', 'undo' ) );
		list( $where, $name ) = $kinds[ $j['kind'] ?? 'bulk' ] ?? $kinds['bulk'];
		if ( $mine ) { $where = $tab; }
		echo '<p class="brand-fleet-note">' . esc_html( $running ? 'You have a ' . $name . ' in progress. ' : 'Your last ' . $name . ' is available. ' ) . '<a href="' . esc_url( self::url( array( 'tab' => $where, 'batch' => $j['id'] ) ) ) . '">' . esc_html( $running ? 'Open it' : 'View results' ) . '</a></p>';
		return array();
	}
	private function bulk(): void {
		echo '<h2>Bulk update</h2><p class="brand-fleet-intro">Set one variable on many sites at once. You always see a preview first; sites edited after the preview are skipped, never overwritten.</p>';
		$j = self::current_batch( 'bulk', 'bulk' );
		self::begin( 'bulk' );
		echo '<div class="brand-fleet-definition-form"><h3>1. Choose sites</h3>';
		self::scope_picker();
		$variables = array_filter( Fleet::definitions(), static fn( $d ) => 'location' === $d['scope'] );
		echo '<h3>2. Choose a variable and its new value</h3><p><label>Variable<br><select name="variable">';
		foreach ( $variables as $key => $d ) { echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $d['label'] . ' (' . ( Fleet::TYPES[ $d['type'] ] ?? $d['type'] ) . ')' ) . '</option>'; }
		echo '</select></label></p><div class="brand-fleet-field" data-show-when="inherit!=1"><span class="brand-fleet-field-label">New value</span>';
		self::typed_inputs( 'variable', 'value', array_map( static fn( $d ) => array( $d['type'], $d['options'] ?? array() ), $variables ), (string) array_key_first( $variables ), '', array( 'aria-label' => 'New value' ) );
		echo '</div><p><label><input type="checkbox" name="inherit" value="1"> Clear the site value instead, so sites use the shared value again</label></p>';
		submit_button( 'Preview changes' ); echo '</div></form>';
		if ( ! $j ) { return; }
		echo '<div id="brand-fleet-current-batch"><h2>Preview and results</h2>';
		self::batch_controls( $j, 'Apply to these sites' );
		$result_page = max( 1, isset( $_GET['result_page'] ) ? absint( $_GET['result_page'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$counts = Fleet_Jobs::counts( $j ); $defs = Fleet::definitions();
		$show = static function ( array $values ) use ( $defs ): string {
			if ( ! $values ) { return '<span class="description">Shared value</span>'; }
			$out = array();
			foreach ( $values as $key => $value ) { $out[] = self::preview( $defs[ $key ]['type'] ?? 'text', (string) $value, $defs[ $key ] ?? array() ); }
			return implode( '<br>', $out );
		};
		echo '<p>' . esc_html( implode( ' · ', array_map( static fn( $status, $count ) => ucfirst( $status ) . ': ' . $count, array_keys( $counts ), $counts ) ) ) . '</p><table class="widefat striped"><thead><tr><th>Site</th><th>Status</th><th>Before</th><th>After</th><th>Details</th></tr></thead><tbody>';
		$rows = Fleet_Jobs::rows( $j, ( $result_page - 1 ) * 25, 26 );
		foreach ( array_slice( $rows, 0, 25 ) as $row ) {
			echo '<tr><td>' . esc_html( Site_Picker::label( (int) $row['site'] ) ) . '</td><td>' . esc_html( ucfirst( $row['status'] ) ) . '</td><td>' . $show( (array) ( $row['before'] ?? array() ) ) . '</td><td>' . $show( (array) ( $row['after'] ?? array() ) ) . '</td><td>' . esc_html( $row['error'] ?? '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- preview() escapes its output.
		}
		echo '</tbody></table><p>';
		if ( $result_page > 1 ) { echo '<a class="button" href="' . esc_url( self::url( array( 'tab' => 'bulk', 'batch' => $j['id'], 'result_page' => $result_page - 1 ) ) ) . '">Previous results</a> '; }
		if ( count( $rows ) > 25 ) { echo '<a class="button" href="' . esc_url( self::url( array( 'tab' => 'bulk', 'batch' => $j['id'], 'result_page' => $result_page + 1 ) ) ) . '">Next results</a>'; }
		echo '</p></div><p id="brand-fleet-batch-changed" hidden>Your settings changed. Preview again to see the matching sites.</p>';
	}
	private function replace(): void {
		echo '<h2>Find &amp; replace</h2><p class="brand-fleet-intro">Find text on fleet sites — for example an old phone number or a brand name typed by hand — and replace it with new text or a variable. You review every match first, can leave pages out, and WordPress keeps a revision of each changed page.</p>';
		$j = self::current_batch( 'replace', 'replace' );
		$spec = $j['replace'] ?? array();
		self::begin( 'replace' );
		echo '<div class="brand-fleet-definition-form"><h3>1. Find</h3><p><label>Text to find<br><input class="large-text" type="text" name="find" required minlength="2" maxlength="200" value="' . esc_attr( $spec['find'] ?? '' ) . '"></label></p>';
		echo '<h3>2. Replace with</h3><fieldset class="brand-fleet-choices"><legend class="screen-reader-text">Replace with</legend><label><input type="radio" name="mode" value="text" checked> New text</label><label><input type="radio" name="mode" value="variable"> A variable, so each site shows its own value</label></fieldset>';
		echo '<p data-show-when="mode=text"><label>New text (leave empty to remove the text)<br><input class="large-text" type="text" name="with" maxlength="500"></label></p><p data-show-when="mode=variable"><label>Variable<br><select name="variable">';
		foreach ( Fleet::definitions() as $key => $d ) { if ( 'embed' !== $d['type'] ) { echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $d['label'] . ' — {{' . $key . '}}' ) . '</option>'; } }
		echo '</select></label></p><h3>3. Where to look</h3>';
		self::scope_picker();
		echo '<fieldset class="brand-fleet-choices"><legend>Content</legend>';
		foreach ( Fleet_Replace::TYPES as $type => $label ) { echo '<label><input type="checkbox" name="types[]" value="' . esc_attr( $type ) . '"' . checked( in_array( $type, Fleet_Replace::DEFAULT_TYPES, true ), true, false ) . '> ' . esc_html( $label ) . '</label>'; }
		echo '</fieldset><details class="brand-fleet-panel"><summary>More options</summary><p><label><input type="checkbox" name="case" value="1"> Match upper and lower case exactly</label></p><p><label><input type="checkbox" name="markup" value="1"> Also replace inside links, image sources and block settings</label><br><span class="description">Use this for an old web address. Visible text is always included.</span></p><p><label><input type="checkbox" name="meta" value="1"> Also replace in SEO titles and descriptions (Yoast, Rank Math) and the custom fields listed under Variables</label><br><span class="description">These fields have no revisions; the preview shows which pages change.</span></p></details>';
		submit_button( 'Find matches' ); echo '</div></form>';
		if ( ! $j ) { return; }
		$pages = (int) ( $j['matched'] ?? 0 ); $left_out = count( $j['left_out'] ?? array() );
		echo '<div id="brand-fleet-current-batch"><h2>Matches for “' . esc_html( $spec['find'] ) . '” → “' . esc_html( $spec['with'] ) . '”</h2>';
		self::batch_controls( $j, 'Replace in selected pages' );
		echo '<p>' . esc_html( sprintf( '%d matching pages on %d of %d sites searched.', $pages, count( $j['hits'] ?? array() ), (int) $j['cursor'] ) ) . ( 'ready' === $j['phase'] ? ' <span id="brand-fleet-selected" data-total="' . esc_attr( (string) $pages ) . '">' . esc_html( ( $pages - $left_out ) . ' selected.' ) . '</span>' : '' ) . '</p>';
		$result_page = max( 1, isset( $_GET['result_page'] ) ? absint( $_GET['result_page'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rows = Fleet_Jobs::hit_rows( $j, ( $result_page - 1 ) * 25, 25 );
		if ( $rows ) {
			$ready = 'ready' === $j['phase'];
			wp_enqueue_script( 'brand-fleet-replace', BRAND_FLEET_URL . 'assets/fleet-replace.js', array(), BRAND_FLEET_VERSION, true );
			wp_localize_script( 'brand-fleet-replace', 'brandFleetReplace', array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'brand_fleet_batch' ), 'id' => $j['id'] ) );
			$types = array( 'page' => 'Page', 'post' => 'Post', 'wp_block' => 'Pattern', 'wp_template_part' => 'Template part', 'wp_template' => 'Template', 'wp_navigation' => 'Menu' );
			echo '<table class="widefat brand-fleet-matches"><thead><tr><th scope="col" class="check-column">Include</th><th scope="col">Page</th><th scope="col">Change</th><th scope="col">Matches</th><th scope="col">Result</th></tr></thead>';
			foreach ( $rows as $i => $row ) {
				echo '<tbody><tr class="brand-fleet-group"><th colspan="5" scope="colgroup">' . esc_html( Site_Picker::label( (int) $row['site'] ) ) . ( ! empty( $row['more'] ) ? ' <span class="brand-fleet-tag">More than ' . esc_html( (string) Fleet_Replace::MAX_MATCHES ) . ' pages — run again after applying</span>' : '' ) . '</th></tr>';
				foreach ( (array) ( $row['matches'] ?? array() ) as $m ) {
					$excluded = isset( $j['left_out'][ $i . ':' . $m['post'] ] );
					$snippet = (array) $m['snippet'];
					echo '<tr><td class="check-column"><input type="checkbox" class="brand-fleet-include" data-row="' . esc_attr( (string) $i ) . '" data-post="' . esc_attr( (string) $m['post'] ) . '"' . checked( ! $excluded, true, false ) . disabled( ! $ready, true, false ) . ' aria-label="' . esc_attr( 'Include ' . $m['title'] ) . '"></td>';
					echo '<td><a href="' . esc_url( get_admin_url( (int) $row['site'], 'post.php?post=' . (int) $m['post'] . '&action=edit' ) ) . '" target="_blank" rel="noopener">' . esc_html( '' !== $m['title'] ? $m['title'] : '(no title)' ) . '</a><br><small>' . esc_html( ( $types[ $m['type'] ] ?? $m['type'] ) . ( 'publish' === $m['status'] ? '' : ' · ' . $m['status'] ) . ( empty( $m['meta'] ) ? '' : ' · also SEO / custom fields' ) ) . '</small></td>';
					echo '<td class="brand-fleet-snippet">' . esc_html( $snippet[0] ?? '' ) . '<del>' . esc_html( $snippet[1] ?? '' ) . '</del><ins>' . esc_html( $spec['with'] ) . '</ins>' . esc_html( $snippet[2] ?? '' ) . '</td><td>' . esc_html( (string) $m['count'] ) . '</td><td>' . esc_html( ucfirst( $m['result'] ?? ( $excluded ? 'Left out' : '' ) ) ) . '</td></tr>';
				}
				echo '</tbody>';
			}
			echo '</table><p>';
			if ( $result_page > 1 ) { echo '<a class="button" href="' . esc_url( self::url( array( 'tab' => 'replace', 'batch' => $j['id'], 'result_page' => $result_page - 1 ) ) ) . '">Previous sites</a> '; }
			if ( count( $j['hits'] ?? array() ) > $result_page * 25 ) { echo '<a class="button" href="' . esc_url( self::url( array( 'tab' => 'replace', 'batch' => $j['id'], 'result_page' => $result_page + 1 ) ) ) . '">Next sites</a>'; }
			echo '</p>';
		} elseif ( ! in_array( $j['phase'], array( 'preview' ), true ) ) {
			echo '<p>No matches found. Try fewer words, turn off exact case, or include links and block settings.</p>';
		}
		if ( 'complete' === $j['phase'] && ! empty( $j['more'] ) ) {
			self::begin( 'replace' );
			echo '<input type="hidden" name="scope" value="chosen"><input type="hidden" name="ids" value="' . esc_attr( implode( ',', $j['more'] ) ) . '"><input type="hidden" name="find" value="' . esc_attr( $spec['find'] ) . '"><input type="hidden" name="mode" value="text"><input type="hidden" name="with" value="' . esc_attr( $spec['with'] ) . '">';
			foreach ( array( 'case', 'markup', 'meta' ) as $flag ) { if ( ! empty( $spec[ $flag ] ) ) { echo '<input type="hidden" name="' . esc_attr( $flag ) . '" value="1">'; } }
			foreach ( $spec['types'] as $type ) { echo '<input type="hidden" name="types[]" value="' . esc_attr( $type ) . '">'; }
			echo '<p>' . esc_html( sprintf( '%d sites had more matches than one pass covers.', count( $j['more'] ) ) ) . ' ';
			submit_button( 'Find the remaining matches', 'secondary', 'submit', false ); echo '</p></form>';
		}
		echo '</div><p id="brand-fleet-batch-changed" hidden>Your search changed. Select Find matches to search again.</p>';
	}
	public function save(): void {
		check_admin_referer( 'brand_fleet_save' );
		try {
			$in = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each typed field validated by services below; nonce checked above.
			$task = sanitize_key( $in['task'] ?? '' );
			if ( 'request' === $task ) {
				$id = absint( $in['site_id'] ?? 0 );
				Fleet_Requests::submit( $id, sanitize_key( $in['key'] ?? '' ), (string) ( $in['value'] ?? '' ), (string) ( $in['note'] ?? '' ) );
				$url = admin_url( 'options-general.php?page=brand-fleet-settings&requested=1#request' );
			} elseif ( 'restore' === $task ) {
				$id = absint( $in['site_id'] ?? 0 );
				Fleet_History::restore( $id, absint( $in['index'] ?? 0 ), (string) ( $in['entry'] ?? '' ) );
				$url = Fleet::can( 'values' ) ? self::url( array( 'site_id' => $id, 'restored' => 1 ) ) : admin_url( 'options-general.php?page=brand-fleet-settings&restored=1' );
			} elseif ( 'site' === $task ) {
				$id = absint( $in['site_id'] ?? 0 ); $patch = array();
				foreach ( Fleet::definitions() as $key => $d ) { if ( 'location' === $d['scope'] && ( Fleet::can( 'values' ) || Fleet::editable( $d, $id ) ) ) { $patch[ $key ] = isset( $in['override'][ $key ] ) ? ( $in['values'][ $key ] ?? '' ) : null; } }
				$connections = isset( $in['connections'] ) ? array_intersect_key( $in, array_flip( array( 'group_id', 'source_id', 'template_id' ) ) ) : null;
				Fleet::save_profile( $id, $patch, $connections, (string) ( $in['expected'] ?? '' ) );
				$url = Fleet::can( 'values' ) ? self::url( array( 'site_id' => $id ) ) : admin_url( 'options-general.php?page=brand-fleet-settings' );
			} else {
				$needs = array( 'definition' => 'manage', 'meta-keys' => 'manage', 'closures' => 'manage', 'local-seo' => 'manage', 'reset-overrides' => 'bulk', 'undo' => 'bulk', 'decide' => 'values', 'alerts' => 'network', 'import-apply' => 'manage', 'segments' => 'manage', 'replace' => 'bulk', 'bulk' => 'bulk', 'import' => 'bulk', 'import-settings' => 'bulk', 'tokens' => 'bulk', 'access' => 'network' );
				if ( ! self::allowed( $needs[ $task ] ?? 'network' ) ) { throw new \RuntimeException( 'You do not have permission for this fleet action.' ); }
				if ( 'definition' === $task ) {
					if ( ( $in['schema_hash'] ?? '' ) !== Fleet::hash( Fleet::definitions() ) ) { throw new \RuntimeException( 'Definitions changed; reload before saving.' ); }
					$in['sites'] = is_array( $in['sites'] ?? null ) ? $in['sites'] : preg_split( '/[\s,]+/', $in['sites'] ?? '' );
					// The form asks one plain question ("who sets it?"); the stored definition keeps scope and access.
					$policies = array( 'network' => array( 'network', 'none' ), 'admins' => array( 'location', 'none' ), 'all' => array( 'location', 'all' ), 'selected' => array( 'location', 'selected' ), 'imported' => array( 'imported', 'none' ) );
					if ( isset( $policies[ $in['policy'] ?? '' ] ) ) { list( $in['scope'], $in['access'] ) = $policies[ $in['policy'] ]; }
					Fleet::save_definition( (string) ( $in['key'] ?? '' ), $in, (string) ( $in['schema_hash'] ?? '' ) ); $url = self::url( array( 'tab' => 'definitions', 'saved' => 1 ) );
				} elseif ( 'replace' === $task ) {
					$spec = array_intersect_key( $in, array_flip( array( 'find', 'types', 'case', 'markup', 'meta' ) ) ) + array( 'types' => array() );
					if ( 'variable' === ( $in['mode'] ?? '' ) ) { $spec['variable'] = $in['variable'] ?? ''; } else { $spec['with'] = $in['with'] ?? ''; }
					$job = Fleet_Jobs::start_replace( self::target_ids( $in ), $spec ); $url = self::url( array( 'tab' => 'replace', 'batch' => $job['id'] ) );
				} elseif ( 'segments' === $task ) {
					$rows = array_filter( (array) ( $in['segments'] ?? array() ), static fn( $row ) => empty( $row['remove'] ) && '' !== trim( (string) ( $row['slug'] ?? '' ) ) );
					uasort( $rows, static fn( $a, $b ) => (int) ( $a['order'] ?? 0 ) <=> (int) ( $b['order'] ?? 0 ) );
					Fleet_Segments::save( array_values( $rows ), (string) ( $in['segments_hash'] ?? '' ) ); $url = self::url( array( 'tab' => 'segments', 'saved' => 1 ) );
				} elseif ( 'access' === $task ) {
					self::save_access( (array) ( $in['caps'] ?? array() ) ); $url = self::url( array( 'tab' => 'access', 'saved' => 1 ) );
				} elseif ( 'import' === $task || 'import-settings' === $task ) {
					$url = Fleet_Import::handle( $task, $in ); $url = self::url( array( 'tab' => 'import' ) + $url );
				} elseif ( 'tokens' === $task ) {
					$job = Fleet_Jobs::start_report( self::target_ids( $in ), array( 'types' => (array) ( $in['types'] ?? array() ) ) ); $url = self::url( array( 'tab' => 'tokens', 'batch' => $job['id'] ) );
				} elseif ( 'undo' === $task ) {
					$job = Fleet_Undo::start( sanitize_text_field( (string) ( $in['batch'] ?? '' ) ) ); $url = self::url( array( 'tab' => 'audit', 'batch' => $job['id'] ) );
				} elseif ( 'decide' === $task ) {
					$r = Fleet_Requests::decide( sanitize_text_field( (string) ( $in['request'] ?? '' ) ), 'approve' === ( $in['decision'] ?? '' ), (string) ( $in['reason'] ?? '' ) ); $url = self::url( array( 'tab' => 'requests', 'decided' => $r['status'] ) );
				} elseif ( 'alerts' === $task ) {
					Fleet_Alerts::save( (string) ( $in['emails'] ?? '' ), (array) ( $in['events'] ?? array() ) ); $url = self::url( array( 'tab' => 'access', 'saved' => 1 ) ) . '#alerts';
				} elseif ( 'reset-overrides' === $task ) {
					$key = sanitize_key( $in['key'] ?? '' );
					if ( 'location' !== ( Fleet::definitions()[ $key ]['scope'] ?? '' ) ) { throw new \InvalidArgumentException( 'Choose a variable sites can set.' ); }
					$job = Fleet_Jobs::start( Fleet_Overrides::sites( $key ), array( $key => null ) ); $url = self::url( array( 'tab' => 'bulk', 'batch' => $job['id'] ) );
				} elseif ( 'local-seo' === $task ) {
					Fleet_Schema::save( $in ); $url = self::url( array( 'tab' => 'seo', 'saved' => 1 ) );
				} elseif ( 'closures' === $task ) {
					Fleet_Hours::save_closures( (array) ( $in['closures'] ?? array() ) ); $url = self::url( array( 'tab' => 'definitions', 'saved' => 1, 'closures' => 1 ) );
				} elseif ( 'meta-keys' === $task ) {
					Fleet::save_meta_keys( (string) ( $in['meta_keys'] ?? '' ) ); $url = self::url( array( 'tab' => 'definitions', 'saved' => 1 ) );
				} elseif ( 'import-apply' === $task ) {
					$document = Fleet_Transfer::decode( (string) ( $in['document'] ?? '' ) );
					$written = Fleet_Transfer::apply_import( $document, (string) ( $in['expected'] ?? '' ) );
					$url = self::url( array( 'tab' => 'definitions', 'imported' => count( $written ) ) );
				} elseif ( 'bulk' === $task ) {
					$ids = self::target_ids( $in );
					$job = Fleet_Jobs::start( $ids, array( sanitize_key( $in['variable'] ?? '' ) => empty( $in['inherit'] ) ? ( $in['value'] ?? '' ) : null ) ); $url = self::url( array( 'tab' => 'bulk', 'batch' => $job['id'] ) );
				} else { throw new \InvalidArgumentException( 'Unknown action.' ); }
			}
			wp_safe_redirect( $url ); exit;
		} catch ( \Throwable $e ) { wp_die( esc_html( $e->getMessage() ), 'Brand Fleet', array( 'response' => 400, 'back_link' => true ) ); }
	}
	public function batch(): void {
		check_ajax_referer( 'brand_fleet_batch', 'nonce' );
		try {
			$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
			$j = empty( $_POST['cancel'] ) ? Fleet_Jobs::step( $id, absint( $_POST['cursor'] ?? 0 ), ! empty( $_POST['confirm'] ) ) : Fleet_Jobs::cancel( $id );
			wp_send_json_success( array( 'cursor' => $j['cursor'], 'phase' => $j['phase'], 'total' => count( $j['ids'] ), 'busy' => ! empty( $j['busy'] ) ) );
		}
		catch ( \Throwable $e ) { wp_send_json_error( $e->getMessage(), 400 ); }
	}
	/** Include or leave out one matching page in a reviewed find and replace. */
	public function exclude(): void {
		check_ajax_referer( 'brand_fleet_batch', 'nonce' );
		try {
			$j = Fleet_Jobs::exclude( sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) ), absint( $_POST['row'] ?? 0 ), absint( $_POST['post'] ?? 0 ), ! empty( $_POST['excluded'] ) );
			wp_send_json_success( array( 'excluded' => count( $j['left_out'] ?? array() ) ) );
		} catch ( \Throwable $e ) { wp_send_json_error( $e->getMessage(), 400 ); }
	}
	public function export_values(): void {
		check_admin_referer( 'brand_fleet_export_values' );
		if ( ! Fleet::can( 'bulk' ) && ! Fleet::can( 'values' ) ) { wp_die( esc_html__( 'Fleet permission required.', 'brand-fleet' ), 'Brand Fleet', array( 'response' => 403 ) ); }
		$segment = sanitize_key( wp_unslash( $_GET['segment'] ?? '' ) );
		$mode = 'shown' === sanitize_key( wp_unslash( $_GET['mode'] ?? '' ) ) ? 'shown' : 'own';
		Fleet_Export::download( self::target_ids( '' === $segment ? array( 'scope' => 'all' ) : array( 'scope' => 'segment', 'segment' => $segment ) ), $mode );
		exit;
	}
	public function export(): void {
		check_admin_referer( 'brand_fleet_export' );
		if ( ! Fleet::can( 'manage' ) ) { wp_die( esc_html__( 'Fleet manager permission required.', 'brand-fleet' ), 'Brand Fleet', array( 'response' => 403 ) ); }
		$document = Fleet_Transfer::export();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="brand-fleet-definitions.json"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo wp_json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}
	/** Preview submits back to this same admin page (not admin-post.php) so it renders inside wp-admin chrome. */
	private function import_view(): void {
		$preview = null; $raw = ''; $error = null;
		if ( 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			check_admin_referer( 'brand_fleet_import_preview' );
			try {
				$tmp = sanitize_text_field( wp_unslash( $_FILES['import_file']['tmp_name'] ?? '' ) );
				if ( '' !== $tmp && is_uploaded_file( $tmp ) ) {
					if ( absint( $_FILES['import_file']['size'] ?? 0 ) > 1_048_576 ) { throw new \InvalidArgumentException( 'File is larger than the 1 MB limit.' ); }
					$raw = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Local tmp file validated by is_uploaded_file() above; never a remote URL.
				} else {
					$raw = (string) wp_unslash( $_POST['document'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed and size-checked by Fleet_Transfer::decode() below; nonce checked above.
				}
				$document = Fleet_Transfer::decode( $raw );
				$preview = Fleet_Transfer::preview_import( $document );
			} catch ( \Throwable $e ) {
				$error = $e->getMessage();
			}
		}
		$this->import_form( $preview, $raw, $error );
	}
	private function import_form( ?array $preview, string $raw, ?string $error ): void {
		echo '<p><a href="' . esc_url( self::url( array( 'tab' => 'definitions' ) ) ) . '">← All variables</a></p>';
		if ( $error ) { echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>'; }
		if ( $preview ) {
			$rows = $preview['rows'];
			$counts = array_count_values( array_column( $rows, 'status' ) );
			$writable = array_filter( $rows, static fn( $row ) => in_array( $row['status'], array( 'new', 'changed' ), true ) );
			$any_kept_delegation = array_filter( $rows, static fn( $row ) => ! empty( $row['kept_delegation'] ) );
			echo '<h2>Import preview</h2>';
			echo '<p>' . esc_html( implode( ' · ', array_map( static fn( $status, $count ) => ucfirst( $status ) . ': ' . $count, array_keys( $counts ), $counts ) ) ) . '</p>';
			if ( $any_kept_delegation ) {
				echo '<p>A key that already allows selected sites to edit it keeps that delegation, instead of being reset to network admins only just because the export dropped it.</p>';
			}
			echo '<table class="widefat striped"><thead><tr><th scope="col">Key</th><th scope="col">Label</th><th scope="col">Status</th><th scope="col">Detail</th></tr></thead><tbody>';
			foreach ( $rows as $key => $row ) {
				echo '<tr><td><code>' . esc_html( $key ) . '</code></td><td>' . esc_html( $row['label'] ) . '</td><td>' . esc_html( ucfirst( $row['status'] ) ) . '</td><td>';
				if ( 'rejected' === $row['status'] ) { echo esc_html( $row['reason'] ); }
				elseif ( 'changed' === $row['status'] ) {
					foreach ( $row['diff'] as $field => $change ) {
						echo '<p><strong>' . esc_html( $field ) . '</strong>: <code>' . esc_html( wp_json_encode( $change['before'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</code> → <code>' . esc_html( wp_json_encode( $change['after'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</code></p>';
					}
				}
				echo '</td></tr>';
			}
			if ( ! $rows ) { echo '<tr><td colspan="4">No definitions found in this file.</td></tr>'; }
			echo '</tbody></table>';
			if ( $writable ) {
				self::begin( 'import-apply' );
				echo '<textarea style="display:none" name="document">' . esc_textarea( $raw ) . '</textarea><input type="hidden" name="expected" value="' . esc_attr( $preview['schema_hash'] ) . '">';
				submit_button( 'Apply import' );
				echo '</form>';
			} else {
				echo '<p>Nothing to import — all keys are unchanged or rejected.</p>';
			}
			echo '<p><a href="' . esc_url( self::url( array( 'tab' => 'definitions', 'view' => 'import' ) ) ) . '">Preview a different file</a></p>';
			return;
		}
		echo '<h2>Import definitions</h2><p>Paste the JSON from an exported network, or upload the downloaded file. You will always see a full preview — new, changed, unchanged, and rejected keys — before anything is written. Only definitions move; per-site values and site delegation lists are never imported.</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( self::url( array( 'tab' => 'definitions', 'view' => 'import' ) ) ) . '">';
		wp_nonce_field( 'brand_fleet_import_preview' );
		echo '<p><label>Upload a file<br><input type="file" name="import_file" accept=".json"></label></p><p><label>Or paste JSON<br><textarea class="large-text" rows="10" name="document">' . esc_textarea( $raw ) . '</textarea></label></p>';
		submit_button( 'Preview import' );
		echo '</form>';
	}
}
