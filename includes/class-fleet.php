<?php
/** Network definitions, explicit site enrollment, and bounded inheritance. */
namespace BrandFleet;

defined( 'ABSPATH' ) || exit;

class Fleet {
	const SCHEMA = 'brand_fleet_schema';
	const PROFILE = 'brand_fleet_profile';
	private static bool $bulk_audit = false;
	public static function bulk_write( callable $callback ) {
		self::$bulk_audit = true;
		try { return $callback(); } finally { self::$bulk_audit = false; }
	}

	public static function network_admin(): bool {
		return is_multisite() && current_user_can( 'manage_network_options' );
	}

	public static function site( int $id ): bool {
		$site = get_site( $id );
		return $site && (int) $site->network_id === get_current_network_id() && ! $site->deleted && ! $site->archived && ! $site->spam;
	}

	public static function definitions(): array {
		$saved = get_network_option( get_current_network_id(), self::SCHEMA, array() );
		$defaults = array();
		foreach ( Settings::defaults() as $option => $value ) {
			if ( Settings::OPT_MASTER_SITE === $option ) { continue; }
			$key = substr( $option, strlen( Settings::PREFIX ) );
			$type = str_contains( $key, 'url' ) || str_starts_with( $key, 'social_' ) ? 'url' : ( 'address' === $key ? 'textarea' : ( 'accent' === $key ? 'color' : 'text' ) );
			$defaults[ $key ] = array( 'label' => ucwords( str_replace( '_', ' ', $key ) ), 'type' => $type, 'default' => $value, 'scope' => 'location', 'access' => 'all', 'sites' => array(), 'required' => false, 'clone' => in_array( $key, array( 'business_name', 'legal_name', 'address' ), true ) );
		}
		return array_replace( $defaults, is_array( $saved ) ? $saved : array() );
	}

	public static function clean( $value, array $definition ): string {
		if ( ! is_string( $value ) || strlen( $value ) > 5000 ) { throw new \InvalidArgumentException( 'Values must be strings of at most 5,000 bytes.' ); }
		switch ( $definition['type'] ) {
			case 'url':
				if ( '' === $value ) { return ''; }
				$url = esc_url_raw( $value, array( 'https', 'http' ) );
				if ( ! $url || ! wp_http_validate_url( $url ) ) { throw new \InvalidArgumentException( 'Enter a valid public http or https URL.' ); }
				return $url;
			case 'email':
				if ( '' !== $value && ! is_email( $value ) ) { throw new \InvalidArgumentException( 'Enter a valid email address.' ); }
				return sanitize_email( $value );
			case 'color':
				if ( '' !== $value && ! sanitize_hex_color( $value ) ) { throw new \InvalidArgumentException( 'Enter a hex color.' ); }
				return $value;
			case 'number':
				if ( '' !== $value && ! preg_match( '/^-?\d+(\.\d+)?$/D', $value ) ) { throw new \InvalidArgumentException( 'Enter a decimal number.' ); }
				return $value;
			case 'textarea': return sanitize_textarea_field( $value );
			default: return sanitize_text_field( $value );
		}
	}

	public static function save_definition( string $key, array $input, string $expected = '' ): void {
		self::locked( 'schema', static fn() => self::write_definition( $key, $input, $expected ) );
	}
	private static function write_definition( string $key, array $input, string $expected ): void {
		if ( '' !== $expected && ! hash_equals( $expected, self::hash( self::definitions() ) ) ) { throw new \RuntimeException( 'Definitions changed; reload before saving.' ); }
		if ( ! self::network_admin() ) { throw new \RuntimeException( 'Network administrator permission required.' ); }
		if ( ! preg_match( '/^[a-z][a-z0-9_]{0,47}$/D', $key ) || 'master_site' === $key ) { throw new \InvalidArgumentException( 'Use a stable lowercase variable key, up to 48 characters.' ); }
		$defs = self::definitions();
		if ( ! isset( $defs[ $key ] ) && count( $defs ) >= 200 ) { throw new \InvalidArgumentException( 'This release supports 200 definitions per network.' ); }
		$type = $input['type'] ?? 'text';
		if ( ! in_array( $type, array( 'text', 'textarea', 'url', 'email', 'color', 'number' ), true ) ) { throw new \InvalidArgumentException( 'Unsupported variable type.' ); }
		if ( isset( $defs[ $key ] ) && $defs[ $key ]['type'] !== $type ) { throw new \InvalidArgumentException( 'Existing types are immutable; create a new key for a different type.' ); }
		if ( ! in_array( $input['scope'] ?? 'location', array( 'network', 'location' ), true ) || ! in_array( $input['access'] ?? 'none', array( 'all', 'selected', 'none' ), true ) ) { throw new \InvalidArgumentException( 'Choose a valid scope and delegation policy.' ); }
		$d = array( 'label' => sanitize_text_field( $input['label'] ?? $key ), 'type' => $type, 'scope' => ( $input['scope'] ?? '' ) === 'network' ? 'network' : 'location', 'access' => in_array( $input['access'] ?? '', array( 'all', 'selected', 'none' ), true ) ? $input['access'] : 'none', 'sites' => array_values( array_unique( array_filter( array_map( 'absint', (array) ( $input['sites'] ?? array() ) ) ) ) ), 'required' => ! empty( $input['required'] ), 'clone' => ! empty( $input['clone'] ) );
		foreach ( $d['sites'] as $id ) { if ( ! self::site( $id ) ) { throw new \InvalidArgumentException( 'Selected locations must belong to this network.' ); } }
		$d['default'] = self::clean( $input['default'] ?? '', $d );
		if ( 'network' === $d['scope'] && $d['required'] && '' === $d['default'] ) { throw new \InvalidArgumentException( 'Required network variables need a default.' ); }
		$defs[ $key ] = $d;
		update_network_option( get_current_network_id(), self::SCHEMA, $defs );
		self::audit( 'definition', 0, array( $key ) );
		Fleet_Cache::schedule();
	}

	public static function profile( int $site ): array {
		$p = get_blog_option( $site, self::PROFILE, array() );
		return is_array( $p ) ? $p : array();
	}

	public static function editable( array $d, int $site ): bool {
		return 'location' === $d['scope'] && ( 'all' === $d['access'] || ( 'selected' === $d['access'] && in_array( $site, $d['sites'], true ) ) );
	}

	public static function resolved( int $site, string $key ): array {
		$d = self::definitions()[ $key ] ?? null;
		if ( ! $d ) { return array( 'value' => '', 'source' => 'undefined' ); }
		$p = self::profile( $site );
		if ( 'network' === $d['scope'] ) { return array( 'value' => $d['default'], 'source' => 'network' ); }
		if ( ! $p && array_key_exists( 'brand_fleet_' . $key, Settings::defaults() ) ) {
			$legacy = get_blog_option( $site, 'brand_fleet_' . $key, '' );
			if ( is_string( $legacy ) && '' !== $legacy ) { return array( 'value' => $legacy, 'source' => 'existing site setting' ); }
		}
		if ( array_key_exists( $key, $p['values'] ?? array() ) ) { return array( 'value' => $p['values'][ $key ], 'source' => 'location' ); }
		$source = (int) ( $p['source_id'] ?? 0 );
		if ( $source && self::site( $source ) ) {
			$parent = self::profile( $source );
			if ( array_key_exists( $key, $parent['values'] ?? array() ) ) { return array( 'value' => $parent['values'][ $key ], 'source' => 'main site ' . $source ); }
			if ( ! $parent && array_key_exists( 'brand_fleet_' . $key, Settings::defaults() ) ) {
				$legacy = get_blog_option( $source, 'brand_fleet_' . $key, '' );
				if ( is_string( $legacy ) && '' !== $legacy ) { return array( 'value' => $legacy, 'source' => 'main site ' . $source ); }
			}
		}
		return array( 'value' => $d['default'], 'source' => 'network' );
	}

	/** Patch uses null to reset inheritance and an empty string as an explicit blank. */
	public static function save_profile( int $site, array $patch, ?array $connections = null, string $expected = '', bool $preview = false ): array {
		return self::locked( 'site_' . $site, static fn() => self::write_profile( $site, $patch, $connections, $expected, $preview ) );
	}

	private static function write_profile( int $site, array $patch, ?array $connections, string $expected, bool $preview ): array {
		if ( ! self::site( $site ) || ( ! self::network_admin() && ! current_user_can_for_site( $site, 'manage_options' ) ) ) { throw new \RuntimeException( 'You cannot manage this site.' ); }
		$old = self::profile( $site );
		if ( '' !== $expected && ! hash_equals( $expected, self::hash( $old ) ) ) { throw new \RuntimeException( 'Site changed since preview; refresh before saving.' ); }
		$p = $old;
		if ( null !== $connections ) {
			if ( ! self::network_admin() ) { throw new \RuntimeException( 'Only network administrators can change connections.' ); }
			foreach ( array( 'group_id', 'source_id', 'template_id' ) as $field ) {
				$id = absint( $connections[ $field ] ?? 0 );
				if ( $id && ! self::site( $id ) ) { throw new \InvalidArgumentException( 'Connections must be active sites in this network.' ); }
				if ( 'source_id' === $field && $id ) {
					if ( $id === $site || ! empty( self::profile( $id )['source_id'] ) ) { throw new \InvalidArgumentException( 'Choose a main data site without its own data source; chains are not supported.' ); }
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Exact relationship-key lookup uses the core blogmeta meta_key index; no meta_value comparison.
					if ( get_sites( array( 'network_id' => get_current_network_id(), 'number' => 1, 'fields' => 'ids', 'meta_key' => 'brand_fleet_source_' . $site ) ) ) { throw new \InvalidArgumentException( 'This site supplies data to locations and must remain a main data site.' ); }
				}
				$p[ $field ] = $id;
			}
			// Explicit enrollment preserves pre-existing identity, without writing every site.
			if ( ! $old ) {
				foreach ( Settings::defaults() as $option => $unused ) {
					$key = substr( $option, strlen( Settings::PREFIX ) );
					$value = get_blog_option( $site, $option, '' );
					if ( isset( self::definitions()[ $key ] ) && is_string( $value ) && '' !== $value ) { $p['values'][ $key ] = self::clean( $value, self::definitions()[ $key ] ); }
				}
			}
		}
		if ( ! $p && null === $connections ) { throw new \RuntimeException( 'Enroll this site from Network Admin first.' ); }
		$defs = self::definitions();
		foreach ( $patch as $key => $value ) {
			if ( ! isset( $defs[ $key ] ) || 'network' === $defs[ $key ]['scope'] ) { throw new \InvalidArgumentException( 'Unknown or network-locked variable: ' . $key ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception is escaped by UI handler; API returns structured errors.
			if ( ! self::network_admin() && ! self::editable( $defs[ $key ], $site ) ) { throw new \RuntimeException( 'This variable is controlled by the network: ' . $key ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception is escaped by UI handler; API returns structured errors.
			if ( null === $value ) { unset( $p['values'][ $key ] ); } else { $p['values'][ $key ] = self::clean( $value, $defs[ $key ] ); }
		}
		foreach ( $defs as $key => $d ) {
			if ( ! $d['required'] ) { continue; }
			$value = 'network' === $d['scope'] ? $d['default'] : ( $p['values'][ $key ] ?? null );
			if ( null === $value && ! empty( $p['source_id'] ) ) { $value = self::resolved( (int) $p['source_id'], $key )['value']; }
			if ( '' === trim( (string) ( $value ?? $d['default'] ) ) ) { throw new \InvalidArgumentException( 'Required variable missing: ' . $key ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception is escaped by UI handler; API returns structured errors.
		}
		$p['revision'] = (int) ( $old['revision'] ?? 0 ) + 1;
		if ( $preview ) { return $p; }
		update_blog_option( $site, self::PROFILE, $p );
		// Encode relationship IDs in indexed meta keys; never filter unindexed meta_value.
		foreach ( array( 'group', 'source' ) as $relation ) {
			$previous = (int) ( $old[ $relation . '_id' ] ?? 0 );
			$current = (int) ( $p[ $relation . '_id' ] ?? 0 );
			if ( $previous && $previous !== $current ) { delete_site_meta( $site, 'brand_fleet_' . $relation . '_' . $previous ); }
			if ( $current ) { update_site_meta( $site, 'brand_fleet_' . $relation . '_' . $current, 1 ); }
		}
		self::audit( 'site', $site, array_keys( $patch ) );
		Fleet_Cache::schedule( $site );
		return $p;
	}

	public static function locked( string $name, callable $callback ) {
		$main = (int) get_main_site_id();
		$key = 'brand_fleet_lock_' . sanitize_key( $name );
		$old = (int) get_blog_option( $main, $key, 0 );
		if ( $old && $old < time() - 300 ) { delete_blog_option( $main, $key ); }
		if ( ! add_blog_option( $main, $key, time() ) ) { throw new \RuntimeException( 'Another update is running. Retry shortly.' ); }
		try { return $callback(); } finally { delete_blog_option( $main, $key ); }
	}

	public static function hash( array $value ): string { return hash( 'sha256', wp_json_encode( $value ) ); }
	public static function audit( string $action, int $site, array $keys ): void {
		if ( self::$bulk_audit ) { return; }
		self::record_activity( array( 'time' => gmdate( 'c' ), 'user' => get_current_user_id(), 'action' => $action, 'site' => $site, 'keys' => $keys ) );
	}
	public static function audit_job( array $job ): void {
		$counts = array_count_values( array_column( $job['rows'], 'status' ) );
		$details = array();
		foreach ( $job['rows'] as $row ) {
			if ( count( $details ) >= 20 ) { break; }
			if ( in_array( $row['status'], array( 'updated', 'skipped' ), true ) ) { $details[] = array_intersect_key( $row, array_flip( array( 'site', 'status', 'error' ) ) ); }
		}
		self::record_activity( array( 'batch' => $job['id'], 'time' => gmdate( 'c' ), 'user' => get_current_user_id(), 'action' => 'bulk', 'keys' => array_keys( $job['patch'] ), 'total' => count( $job['ids'] ), 'updated' => $counts['updated'] ?? 0, 'skipped' => $counts['skipped'] ?? 0, 'phase' => $job['phase'], 'details' => $details ) );
	}
	private static function record_activity( array $entry ): void {
		self::locked( 'audit', static function () use ( $entry ) {
			$log = (array) get_network_option( get_current_network_id(), 'brand_fleet_audit', array() );
			if ( isset( $entry['batch'] ) ) {
				$log = array_values( array_filter( $log, static fn( $row ) => ( $row['batch'] ?? '' ) !== $entry['batch'] ) );
			}
			$log[] = $entry;
			update_network_option( get_current_network_id(), 'brand_fleet_audit', array_slice( $log, -100 ) );
		} );
	}
}
