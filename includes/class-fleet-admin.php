<?php
/** Network registry, searchable sites, and local delegated fields. */
namespace BrandFleet;

defined( 'ABSPATH' ) || exit;
class Fleet_Admin {
	public function init(): void {
		add_action( 'wp_ajax_brand_fleet_site_picker', array( Site_Picker::class, 'search' ) );
		add_action( 'network_admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_brand_fleet_save', array( $this, 'save' ) );
		add_action( 'wp_ajax_brand_fleet_batch', array( $this, 'batch' ) );
	}
	public function menu(): void {
		add_menu_page( 'Brand Fleet', 'Brand Fleet', 'manage_network_options', 'brand-fleet', array( $this, 'render' ), 'dashicons-admin-multisite', 30 );
	}
	private static function url( array $args = array() ): string { return add_query_arg( $args, network_admin_url( 'admin.php?page=brand-fleet' ) ); }
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
	public static function values( int $site ): void {
		$p = Fleet::profile( $site );
		$original_hash = Fleet::hash( $p );
		if ( ! $p ) {
			foreach ( Fleet::definitions() as $key => $d ) {
				$legacy = get_blog_option( $site, 'brand_fleet_' . $key, '' );
				if ( is_string( $legacy ) && '' !== $legacy ) { $p['values'][ $key ] = $legacy; }
			}
		}
		echo '<input type="hidden" name="expected" value="' . esc_attr( $original_hash ) . '"><table class="widefat striped"><thead><tr><th>Variable</th><th>Effective value / source</th><th>Site override</th></tr></thead><tbody>';
		foreach ( Fleet::definitions() as $key => $d ) {
			if ( ! Fleet::network_admin() && ! Fleet::editable( $d, $site ) ) { continue; }
			$r = Fleet::resolved( $site, $key );
			$editable = 'location' === $d['scope'] && ( Fleet::network_admin() || Fleet::editable( $d, $site ) );
			echo '<tr><th scope="row">' . esc_html( $d['label'] ) . '<br><code>{{' . esc_html( $key ) . '}}</code>' . ( $d['required'] ? '<br>Required' : '' ) . '</th><td>' . nl2br( esc_html( $r['value'] ) ) . '<br><small>' . esc_html( $r['source'] ) . '</small></td><td>';
			if ( $editable ) {
				echo '<label><input type="checkbox" name="override[' . esc_attr( $key ) . ']" value="1" ' . checked( array_key_exists( $key, $p['values'] ?? array() ), true, false ) . '> Override inherited value</label><br><textarea rows="2" class="large-text" aria-label="' . esc_attr( $d['label'] ) . '" name="values[' . esc_attr( $key ) . ']">' . esc_textarea( $p['values'][ $key ] ?? '' ) . '</textarea>';
			} else { echo 'Controlled by network'; }
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}
	public function local(): void {
		echo '<div class="wrap"><h1>Brand Identity</h1><p>Uncheck an override to inherit from your main data site or the network. Only fields delegated to your site are shown.</p>';
		self::begin( 'site' );
		echo '<input type="hidden" name="site_id" value="' . esc_attr( (string) get_current_blog_id() ) . '">';
		self::values( get_current_blog_id() ); submit_button( 'Save site variables' ); echo '</form></div>';
	}
	public function render(): void {
		if ( ! Fleet::network_admin() ) { return; }
		// Read-only navigation parameters; all writes use separate nonce-protected handlers.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'sites'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_enqueue_style( 'brand-fleet-admin', BRAND_FLEET_URL . 'assets/fleet-admin.css', array(), BRAND_FLEET_VERSION );
		echo '<div class="wrap brand-fleet"><h1>Brand Fleet</h1><nav>';
		foreach ( array( 'sites' => 'Sites in Fleet', 'definitions' => 'Variable definitions', 'bulk' => 'Bulk updates', 'audit' => 'Recent activity' ) as $key => $label ) { echo '<a class="button" href="' . esc_url( self::url( array( 'tab' => $key ) ) ) . '">' . esc_html( $label ) . '</a> '; }
		echo '</nav><p>Network defaults → main data site → site override. Network-scoped variables always use the network value.</p>';
		if ( 'definitions' === $tab ) { $this->definitions(); }
		elseif ( 'bulk' === $tab ) { $this->bulk(); }
		elseif ( 'audit' === $tab ) { $this->activity(); }
		else { $this->sites(); }
		echo '</div>';
	}
	private function activity(): void {
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
				$text = implode( ' ', array_merge( $row['keys'], $labels, array( $row['time'], gmdate( 'M j, Y', strtotime( $row['time'] ) ), $user ? $user->display_name . ' ' . $user->user_login : 'Deleted user', $row['action'], $row['phase'] ?? '', 'definition' === $row['action'] ? 'Variable definition saved' : ( 'bulk' === $row['action'] ? 'Bulk update' : 'Site updated similar edits' ) ) ) );
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
			$action = $is_bulk ? ( 'complete' === $row['phase'] ? 'Bulk update completed' : 'Bulk update in progress / paused' ) : ( 'definition' === $row['action'] ? 'Variable definition saved' : ( count( $row['sites'] ) > 1 ? 'Similar site edits' : 'Site updated' ) );
			echo '<tr><td>' . esc_html( gmdate( 'M j, Y H:i', strtotime( $row['time'] ) ) ) . '</td><td>' . esc_html( $user ? $user->display_name : 'Deleted user' ) . '</td><td>' . esc_html( $action ) . '</td><td>' . esc_html( $labels ? implode( ', ', $labels ) : 'Connections / enrollment' ) . '</td><td>';
			if ( $is_bulk ) {
				echo esc_html( sprintf( '%d sites: %d updated, %d skipped, %d pending', $row['total'], $row['updated'], $row['skipped'], max( 0, $row['total'] - $row['updated'] - $row['skipped'] ) ) );
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
		$defs = Fleet::definitions(); $d = $defs[ $edit ] ?? array();
		if ( isset( $_GET['saved'] ) ) { echo '<div class="notice notice-success"><p>Variable saved.</p></div>'; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice.
		$new = isset( $_GET['new_variable'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		if ( ! $edit && ! $new ) {
			echo '<h2>Variable definitions <a class="page-title-action" href="' . esc_url( self::url( array( 'tab' => 'definitions', 'new_variable' => 1 ) ) ) . '">Add new variable</a></h2><p>Define the information your sites share, its default value, and who can change it.</p><form method="get" class="brand-fleet-search-form"><input type="hidden" name="page" value="brand-fleet"><input type="hidden" name="tab" value="definitions"><p><label>Search variables <input type="search" name="q" value="' . esc_attr( $search ) . '"></label> <button class="button">Search</button></p></form>';
			$filtered = array_filter( $defs, static fn( $def, $key ) => ! $search || false !== stripos( $key . ' ' . $def['label'], $search ), ARRAY_FILTER_USE_BOTH );
			$page = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<p>' . esc_html( (string) count( $filtered ) ) . ' variables</p><table class="widefat striped"><thead><tr><th scope="col">Variable</th><th scope="col">Type</th><th scope="col">Value policy</th><th scope="col">Site editing</th><th scope="col">Setup</th></tr></thead><tbody>';
			foreach ( array_slice( $filtered, ( $page - 1 ) * 20, 20, true ) as $key => $def ) {
				$access = array( 'all' => 'All sites', 'selected' => 'Selected sites', 'none' => 'Network admins only' );
				echo '<tr><td><strong><a href="' . esc_url( self::url( array( 'tab' => 'definitions', 'key' => $key ) ) ) . '">' . esc_html( $def['label'] ) . '</a></strong><br><code>{{' . esc_html( $key ) . '}}</code></td><td>' . esc_html( ucfirst( $def['type'] ) ) . '</td><td>' . esc_html( 'network' === $def['scope'] ? 'Locked network value' : 'Site overrides allowed' ) . '</td><td>' . esc_html( 'network' === $def['scope'] ? 'Network admins only' : $access[ $def['access'] ] ) . '</td><td>' . esc_html( implode( ' · ', array_filter( array( $def['required'] ? 'Required' : '', $def['clone'] ? 'Prompt on Add Site' : '' ) ) ) ) . '</td></tr>';
			}
			if ( ! $filtered ) { echo '<tr><td colspan="5">No variables found. Try another search or add a new variable.</td></tr>'; }
			echo '</tbody></table><p>';
			if ( $page > 1 ) { echo '<a class="button" href="' . esc_url( self::url( array( 'tab' => 'definitions', 'q' => $search, 'paged' => $page - 1 ) ) ) . '">Previous</a> '; }
			if ( count( $filtered ) > $page * 20 ) { echo '<a class="button" href="' . esc_url( self::url( array( 'tab' => 'definitions', 'q' => $search, 'paged' => $page + 1 ) ) ) . '">Next</a>'; }
			echo '</p>'; return;
		}
		echo '<p><a href="' . esc_url( self::url( array( 'tab' => 'definitions' ) ) ) . '">← All variables</a></p>';
		if ( $edit && ! isset( $defs[ $edit ] ) ) { echo '<p>This variable was not found.</p>'; return; }
		echo '<h2>' . esc_html( $edit ? 'Edit variable: ' . $d['label'] : 'Add new variable' ) . '</h2><p>Set up this field once, then use its token in content across your connected sites.</p>'; self::begin( 'definition' );
		echo '<div class="brand-fleet-definition-form"><h3>Name and format</h3>';
		if ( $edit ) { echo '<p>Stable key: <code>' . esc_html( $edit ) . '</code></p><input type="hidden" name="key" value="' . esc_attr( $edit ) . '">'; } else { self::field( 'key', 'Stable key (for example opening_hours)' ); }
		self::field( 'label', 'Label', $d['label'] ?? '' );
		self::select( 'type', 'Value type (cannot change after creation)', array_combine( array( 'text', 'textarea', 'url', 'email', 'color', 'number' ), array( 'Text', 'Multiline text', 'URL', 'Email', 'Color', 'Number' ) ), $d['type'] ?? 'text' );
		echo '<h3>Defaults and permissions</h3><p>Choose a shared default, then decide whether sites can provide their own value.</p>';
		echo '<p><label>Network default<br><textarea class="large-text" rows="3" name="default">' . esc_textarea( $d['default'] ?? '' ) . '</textarea></label></p>';
		self::select( 'scope', 'Value policy', array( 'network' => 'Locked to network value', 'location' => 'Allow site values and inheritance' ), $d['scope'] ?? 'location' );
		self::select( 'access', 'Who may edit site overrides?', array( 'none' => 'Network administrators only', 'all' => 'All site administrators', 'selected' => 'Selected site administrators' ), $d['access'] ?? 'none' );
		Site_Picker::render( 'sites', 'Sites allowed to edit (when Selected site administrators is chosen)', $d['sites'] ?? array(), true );
		echo '<h3>New site setup</h3>';
		foreach ( array( 'required' => 'Require a non-empty effective value', 'clone' => 'Prompt on Add Site; never copy this value from a template' ) as $key => $label ) { echo '<p><label><input type="checkbox" name="' . esc_attr( $key ) . '" value="1" ' . checked( ! empty( $d[ $key ] ), true, false ) . '> ' . esc_html( $label ) . '</label></p>'; }
		echo '<input type="hidden" name="schema_hash" value="' . esc_attr( Fleet::hash( $defs ) ) . '">'; submit_button( $edit ? 'Save changes' : 'Add variable' ); echo '<a href="' . esc_url( self::url( array( 'tab' => 'definitions' ) ) ) . '">Cancel</a></div></form>';
	}
	private function sites(): void {
		$id = isset( $_GET['site_id'] ) ? absint( $_GET['site_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $id && Fleet::site( $id ) ) {
			$p = Fleet::profile( $id ); echo '<h2>Site ' . esc_html( Site_Picker::label( $id ) ) . '</h2>'; self::begin( 'site' );
			echo '<input type="hidden" name="site_id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="connections" value="1">';
			foreach ( array( 'group_id' => 'Connected brand hub', 'source_id' => 'Main data source', 'template_id' => 'Starting template (reference only)' ) as $field => $label ) { Site_Picker::render( $field, $label, array( $p[ $field ] ?? 0 ), false, 'source_id' === $field ? 'Network defaults' : 'None', 'source_id' === $field ? $id : 0 ); }
			self::values( $id ); submit_button( $p ? 'Save site' : 'Enroll site and save' ); echo '</form>'; return;
		}
		$q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<h2>Sites in Fleet</h2><form class="brand-fleet-search-form"><input type="hidden" name="page" value="brand-fleet"><label>Search domain or path <input type="search" name="q" value="' . esc_attr( $q ) . '"></label><button class="button">Search</button></form><table class="widefat striped"><thead><tr><th>Site</th><th>Brand hub</th><th>Main data site</th><th>State</th></tr></thead><tbody>';
		$sites = Fleet::listed_sites( $q, $page );
		foreach ( array_slice( $sites, 0, 25 ) as $site ) { $p = Fleet::profile( (int) $site->blog_id ); echo '<tr><td><a href="' . esc_url( self::url( array( 'site_id' => $site->blog_id ) ) ) . '">' . esc_html( Site_Picker::label( (int) $site->blog_id ) ) . '</a></td><td>' . esc_html( empty( $p['group_id'] ) ? 'None' : Site_Picker::label( (int) $p['group_id'] ) ) . '</td><td>' . esc_html( empty( $p['source_id'] ) ? 'Network defaults' : Site_Picker::label( (int) $p['source_id'] ) ) . '</td><td>' . ( $p ? 'Managed' : 'Not enrolled' ) . '</td></tr>'; }
		echo '</tbody></table><p>';
		if ( $page > 1 ) { echo '<a class="button" href="' . esc_url( self::url( array( 'paged' => $page - 1, 'q' => $q ) ) ) . '">Previous</a> '; }
		if ( count( $sites ) > 25 ) { echo '<a class="button" href="' . esc_url( self::url( array( 'paged' => $page + 1, 'q' => $q ) ) ) . '">Next</a>'; } echo '</p>';
	}
	private function bulk(): void {
		echo '<h2>Prepare a bulk update</h2><p>Preview before applying. Changed sites are skipped instead of overwriting edits made after preview. Batches are 25 sites; you may close this page and resume later.</p>'; self::begin( 'bulk' );
		Site_Picker::render( 'ids', 'Choose sites to update', array(), true ); Site_Picker::render( 'group_id', 'Or choose a brand hub to update all its enrolled sites', array(), false, 'Use selected sites above' );
		echo '<p class="description">Choosing a hub replaces the individual site selection for this batch. Review the preview before applying.</p>';
		self::select( 'variable', 'Variable', array_map( static fn( $d ) => $d['label'], array_filter( Fleet::definitions(), static fn( $d ) => 'location' === $d['scope'] ) ), '' );
		echo '<p><label>New value<br><textarea name="value" rows="3" class="large-text"></textarea></label></p><p><label><input type="checkbox" name="inherit" value="1">Reset to inherited value</label></p>'; submit_button( 'Prepare preview' ); echo '</form>';
		$j = Fleet_Jobs::get(); if ( ! $j ) { return; }
		$batch = isset( $_GET['batch'] ) ? sanitize_text_field( wp_unslash( $_GET['batch'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only batch view.
		if ( $batch !== $j['id'] ) {
			echo '<p>A previous batch is available (' . esc_html( (string) count( $j['ids'] ) ) . ' sites). <a href="' . esc_url( self::url( array( 'tab' => 'bulk', 'batch' => $j['id'] ) ) ) . '">View or resume previous batch</a>. Prepare a preview above to see your new selection.</p>'; return;
		}
		wp_enqueue_script( 'brand-fleet-batch', BRAND_FLEET_URL . 'assets/fleet-batch.js', array(), BRAND_FLEET_VERSION, true );
		echo '<div id="brand-fleet-current-batch">';
		echo '<h2>Current batch</h2><p id="brand-fleet-batch-status">' . esc_html( $j['phase'] . ' · ' . $j['cursor'] . '/' . count( $j['ids'] ) ) . '</p>';
		$result_page = max( 1, isset( $_GET['result_page'] ) ? absint( $_GET['result_page'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$counts = array_count_values( array_column( $j['rows'], 'status' ) );
		echo '<p>' . esc_html( implode( ' · ', array_map( static fn( $status, $count ) => $status . ': ' . $count, array_keys( $counts ), $counts ) ) ) . '</p><p>Before and after show site overrides. An empty object means the value is inherited.</p><table class="widefat striped"><thead><tr><th>Site</th><th>Status</th><th>Before</th><th>After</th><th>Details</th></tr></thead><tbody>';
		foreach ( array_slice( $j['rows'], ( $result_page - 1 ) * 25, 25 ) as $row ) {
			echo '<tr><td>' . esc_html( Site_Picker::label( (int) $row['site'] ) ) . '</td><td>' . esc_html( $row['status'] ) . '</td><td><code>' . esc_html( wp_json_encode( (object) ( $row['before'] ?? array() ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</code></td><td><code>' . esc_html( wp_json_encode( (object) ( $row['after'] ?? array() ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</code></td><td>' . esc_html( $row['error'] ?? '' ) . '</td></tr>';
		}
		echo '</tbody></table><p>';
		if ( $result_page > 1 ) { echo '<a class="button" href="' . esc_url( self::url( array( 'tab' => 'bulk', 'batch' => $j['id'], 'result_page' => $result_page - 1 ) ) ) . '">Previous results</a> '; }
		if ( count( $j['rows'] ) > $result_page * 25 ) { echo '<a class="button" href="' . esc_url( self::url( array( 'tab' => 'bulk', 'batch' => $j['id'], 'result_page' => $result_page + 1 ) ) ) . '">Next results</a>'; }
		echo '</p>';
		if ( 'complete' !== $j['phase'] ) {
			echo '<button type="button" class="button button-primary" id="brand-fleet-batch-run">' . ( 'ready' === $j['phase'] ? 'Apply this reviewed batch' : 'Continue batch' ) . '</button>';
			wp_enqueue_script( 'brand-fleet-batch', BRAND_FLEET_URL . 'assets/fleet-batch.js', array(), BRAND_FLEET_VERSION, true );
			wp_localize_script( 'brand-fleet-batch', 'brandFleetBatch', array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'brand_fleet_batch' ), 'id' => $j['id'], 'cursor' => $j['cursor'], 'phase' => $j['phase'] ) );
		}
		echo '</div><p id="brand-fleet-batch-changed" hidden>Your selection or update settings changed. Prepare a new preview to see the matching sites.</p>';
	}
	public function save(): void {
		check_admin_referer( 'brand_fleet_save' );
		try {
			$in = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each typed field validated by services below; nonce checked above.
			$task = sanitize_key( $in['task'] ?? '' );
			if ( 'site' === $task ) {
				$id = absint( $in['site_id'] ?? 0 ); $patch = array();
				foreach ( Fleet::definitions() as $key => $d ) { if ( 'location' === $d['scope'] && ( Fleet::network_admin() || Fleet::editable( $d, $id ) ) ) { $patch[ $key ] = isset( $in['override'][ $key ] ) ? ( $in['values'][ $key ] ?? '' ) : null; } }
				$connections = isset( $in['connections'] ) ? array_intersect_key( $in, array_flip( array( 'group_id', 'source_id', 'template_id' ) ) ) : null;
				Fleet::save_profile( $id, $patch, $connections, (string) ( $in['expected'] ?? '' ) );
				$url = Fleet::network_admin() ? self::url( array( 'site_id' => $id ) ) : admin_url( 'options-general.php?page=brand-fleet-settings' );
			} else {
				if ( ! Fleet::network_admin() ) { throw new \RuntimeException( 'Network permission required.' ); }
				if ( 'definition' === $task ) {
					if ( ( $in['schema_hash'] ?? '' ) !== Fleet::hash( Fleet::definitions() ) ) { throw new \RuntimeException( 'Definitions changed; reload before saving.' ); }
					$in['sites'] = is_array( $in['sites'] ?? null ) ? $in['sites'] : preg_split( '/[\s,]+/', $in['sites'] ?? '' ); Fleet::save_definition( (string) ( $in['key'] ?? '' ), $in, (string) ( $in['schema_hash'] ?? '' ) ); $url = self::url( array( 'tab' => 'definitions', 'saved' => 1 ) );
				} elseif ( 'bulk' === $task ) {
					$ids = is_array( $in['ids'] ?? null ) ? $in['ids'] : preg_split( '/[\s,]+/', $in['ids'] ?? '' );
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Exact relationship-key lookup uses the core blogmeta meta_key index; no meta_value comparison.
					if ( ! empty( $in['group_id'] ) ) { $ids = get_sites( array( 'network_id' => get_current_network_id(), 'number' => 10001, 'fields' => 'ids', 'meta_key' => 'brand_fleet_group_' . absint( $in['group_id'] ), 'deleted' => 0, 'archived' => 0, 'spam' => 0 ) ); }
					$job = Fleet_Jobs::start( $ids, array( sanitize_key( $in['variable'] ?? '' ) => empty( $in['inherit'] ) ? ( $in['value'] ?? '' ) : null ) ); $url = self::url( array( 'tab' => 'bulk', 'batch' => $job['id'] ) );
				} else { throw new \InvalidArgumentException( 'Unknown action.' ); }
			}
			wp_safe_redirect( $url ); exit;
		} catch ( \Throwable $e ) { wp_die( esc_html( $e->getMessage() ), 'Brand Fleet', array( 'response' => 400, 'back_link' => true ) ); }
	}
	public function batch(): void {
		check_ajax_referer( 'brand_fleet_batch', 'nonce' );
		try { $j = Fleet_Jobs::step( sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) ), absint( $_POST['cursor'] ?? 0 ), ! empty( $_POST['confirm'] ) ); wp_send_json_success( array( 'cursor' => $j['cursor'], 'phase' => $j['phase'], 'total' => count( $j['ids'] ) ) ); }
		catch ( \Throwable $e ) { wp_send_json_error( $e->getMessage(), 400 ); }
	}
}
