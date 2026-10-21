<?php
/** Network definitions, explicit site enrollment, and bounded inheritance. */
namespace BrandFleet;

defined( 'ABSPATH' ) || exit;

class Fleet {
	const SCHEMA = 'brand_fleet_schema';
	const PROFILE = 'brand_fleet_profile';
	/** Indexed site-meta marker so Sites in Fleet can list managed sites first. */
	const MANAGED = 'brand_fleet_managed';
	/** Exception code for a held lock: callers may wait and retry. */
	const BUSY = 409;
	/** Value types and their admin labels. A definition's type never changes after creation. */
	const TYPES = array( 'text' => 'Text', 'textarea' => 'Multiline text', 'url' => 'Link (URL)', 'image' => 'Image', 'email' => 'Email', 'phone' => 'Phone number', 'color' => 'Color', 'number' => 'Number', 'select' => 'Dropdown', 'toggle' => 'Yes / No', 'date' => 'Date', 'hours' => 'Opening hours', 'list' => 'List', 'embed' => 'Embed code' );
	/** Optional definition settings; older definitions may not carry them. */
	const EXTRAS = array( 'help' => '', 'group' => '', 'options' => array(), 'placement' => 'head', 'targets' => array(), 'segment' => '', 'segment_defaults' => array(), 'identifier' => false );
	/** Where an embed prints. */
	const PLACEMENTS = array( 'head' => 'In the page head', 'body' => 'At the start of the page body', 'footer' => 'At the end of the page' );
	/** Network option: custom field keys whose front-end values swap {{tokens}}. */
	const META_KEYS = 'brand_fleet_meta_keys';
	/** network: one value (per segment); location: per site, people may set it; imported: per site, written only by imports. */
	const SCOPES = array( 'network', 'location', 'imported' );
	/** Bulk writes are audited and purged once per checkpoint by Fleet_Jobs, not once per site. */
	private static bool $bulk = false;
	public static function bulk_write( callable $callback ) {
		self::$bulk = true;
		try { return $callback(); } finally { self::$bulk = false; }
	}
	/** Imported-scope values can only be written inside an import. */
	private static bool $importing = false;
	public static function import_write( callable $callback ) {
		self::$importing = true;
		try { return $callback(); } finally { self::$importing = false; }
	}

	/** Why values are changing, for value history: edit, bulk, import, undo, request or restore. */
	private static string $via = '';
	public static function via( string $via, callable $callback ) {
		$previous = self::$via; self::$via = $via;
		try { return $callback(); } finally { self::$via = $previous; }
	}
	public static function current_via(): string {
		return self::$via ? self::$via : ( self::$importing ? 'import' : ( self::$bulk ? 'bulk' : 'edit' ) );
	}

	public static function network_admin(): bool {
		return is_multisite() && current_user_can( 'manage_network_options' );
	}
	/** Fleet capabilities, granted to roles on the main site so central editors need not be super admins. */
	const CAPS = array( 'manage' => 'brand_fleet_manage', 'bulk' => 'brand_fleet_bulk', 'values' => 'brand_fleet_edit_values' );
	/** Network admins can do everything; "manage" includes the other two. */
	public static function can( string $what ): bool {
		if ( ! is_multisite() ) { return false; }
		if ( self::network_admin() ) { return true; }
		$main = (int) get_main_site_id();
		if ( current_user_can_for_site( $main, self::CAPS['manage'] ) ) { return true; }
		return isset( self::CAPS[ $what ] ) && 'manage' !== $what && current_user_can_for_site( $main, self::CAPS[ $what ] );
	}
	public static function can_any(): bool { return self::can( 'manage' ) || self::can( 'bulk' ) || self::can( 'values' ); }

	/** Per-request caches of definitions and resolved values; cleared whenever fleet data is written. */
	private static array $memo = array();
	public static function flush(): void { self::$memo = array(); Fleet_Segments::flush(); Settings::flush(); }

	/**
	 * wp_update_post() that leaves the page template alone. Core re-sends the stored _wp_page_template on
	 * every update and, when the site does not register it (yet), resets it to "default" or, with
	 * $wp_error, refuses the whole update. An empty page_template skips that check; the meta is untouched.
	 *
	 * @param array $postarr  Slashed post fields, with ID.
	 * @param bool  $wp_error Return a WP_Error on failure.
	 * @return int|\WP_Error
	 */
	public static function update_post( array $postarr, bool $wp_error = false ) {
		return wp_update_post( $postarr + array( 'page_template' => '' ), $wp_error );
	}

	public static function site( int $id ): bool {
		$site = get_site( $id );
		return $site && (int) $site->network_id === get_current_network_id() && ! $site->deleted && ! $site->archived && ! $site->spam;
	}

	public static function definitions(): array {
		$network = get_current_network_id();
		if ( isset( self::$memo['defs'][ $network ] ) ) { return self::$memo['defs'][ $network ]; }
		$saved = get_network_option( $network, self::SCHEMA, array() );
		$defaults = array();
		foreach ( Settings::defaults() as $option => $value ) {
			if ( Settings::OPT_MASTER_SITE === $option ) { continue; }
			$key = substr( $option, strlen( Settings::PREFIX ) );
			$type = str_contains( $key, 'url' ) || str_starts_with( $key, 'social_' ) ? 'url' : ( in_array( $key, array( 'address', 'footer_services' ), true ) ? 'textarea' : ( 'accent' === $key ? 'color' : 'text' ) );
			$defaults[ $key ] = array( 'label' => ucwords( str_replace( '_', ' ', $key ) ), 'type' => $type, 'default' => $value, 'scope' => 'location', 'access' => 'all', 'sites' => array(), 'required' => false, 'clone' => in_array( $key, array( 'business_name', 'legal_name', 'address' ), true ) );
		}
		return self::$memo['defs'][ $network ] = array_replace( $defaults, is_array( $saved ) ? $saved : array() );
	}

	public static function clean( $value, array $definition ): string {
		if ( ! is_string( $value ) || strlen( $value ) > 5000 ) { throw new \InvalidArgumentException( 'Values must be strings of at most 5,000 bytes.' ); }
		switch ( $definition['type'] ) {
			case 'url':
			case 'image':
				if ( '' === $value ) { return ''; }
				$url = esc_url_raw( $value, array( 'https', 'http' ) );
				// Media Library uploads are always accepted, even on hosts the public-URL check cannot resolve.
				$uploads = 'image' === $definition['type'] ? (string) ( wp_get_upload_dir()['baseurl'] ?? '' ) : '';
				if ( $url && $uploads && str_starts_with( set_url_scheme( $url, 'https' ), trailingslashit( set_url_scheme( $uploads, 'https' ) ) ) ) { return $url; }
				if ( ! $url || ! wp_http_validate_url( $url ) ) { throw new \InvalidArgumentException( 'image' === $definition['type'] ? 'Enter a valid image URL, or choose an image from the Media Library.' : 'Enter a valid public http or https URL.' ); }
				return $url;
			case 'phone':
				if ( '' !== $value && ! preg_match( '/^[0-9+().\s\-#*x]{3,40}$/iD', trim( $value ) ) ) { throw new \InvalidArgumentException( 'Enter a phone number using digits, spaces, +, -, ( ) or x for an extension.' ); }
				return sanitize_text_field( $value );
			case 'email':
				if ( '' !== $value && ! is_email( $value ) ) { throw new \InvalidArgumentException( 'Enter a valid email address.' ); }
				return sanitize_email( $value );
			case 'color':
				if ( '' !== $value && ! sanitize_hex_color( $value ) ) { throw new \InvalidArgumentException( 'Enter a hex color, for example #1e73be.' ); }
				return $value;
			case 'number':
				if ( '' !== $value && ! preg_match( '/^-?\d+(\.\d+)?$/D', $value ) ) { throw new \InvalidArgumentException( 'Enter a decimal number.' ); }
				return $value;
			case 'select':
				if ( '' !== $value && ! in_array( $value, (array) ( $definition['options'] ?? array() ), true ) ) { throw new \InvalidArgumentException( 'Choose one of the listed options.' ); }
				return $value;
			case 'toggle':
				if ( ! in_array( $value, array( '', '0', '1' ), true ) ) { throw new \InvalidArgumentException( 'Choose yes or no.' ); }
				return $value;
			case 'date':
				if ( '' === $value ) { return ''; }
				$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
				if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) { throw new \InvalidArgumentException( 'Enter a date as YYYY-MM-DD.' ); }
				return $value;
			case 'hours':
				return Fleet_Hours::clean( $value );
			case 'list':
				return self::list_items( $value );
			case 'embed':
				// Raw markup by design: only network admins who may post unfiltered HTML can save it (see validate_definition()).
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
		if ( ! self::can( 'manage' ) ) { throw new \RuntimeException( 'Fleet manager permission required.' ); }
		$defs = self::definitions();
		$d = self::validate_definition( $defs, $key, $input );
		$defs[ $key ] = $d;
		update_network_option( get_current_network_id(), self::SCHEMA, $defs );
		self::flush();
		Fleet_Segments::reindex_all();
		self::audit( 'definition', 0, array( $key ) );
		Fleet_Cache::schedule();
	}
	/** Pure field-validation rules, shared by the single-definition save path and definition import. */
	public static function validate_definition( array $defs, string $key, array $input ): array {
		if ( ! preg_match( '/^[a-z][a-z0-9_]{0,47}$/D', $key ) || 'master_site' === $key ) { throw new \InvalidArgumentException( 'Use a stable lowercase variable key, up to 48 characters.' ); }
		if ( ! isset( $defs[ $key ] ) && count( $defs ) >= 200 ) { throw new \InvalidArgumentException( 'This release supports 200 definitions per network.' ); }
		$type = $input['type'] ?? 'text';
		if ( ! isset( self::TYPES[ $type ] ) ) { throw new \InvalidArgumentException( 'Unsupported variable type.' ); }
		if ( isset( $defs[ $key ] ) && $defs[ $key ]['type'] !== $type ) { throw new \InvalidArgumentException( 'Existing types are immutable; create a new key for a different type.' ); }
		if ( ! in_array( $input['scope'] ?? 'location', self::SCOPES, true ) || ! in_array( $input['access'] ?? 'none', array( 'all', 'selected', 'none' ), true ) ) { throw new \InvalidArgumentException( 'Choose a valid scope and delegation policy.' ); }
		$scope = in_array( $input['scope'] ?? '', self::SCOPES, true ) ? $input['scope'] : 'location';
		$d = array( 'label' => sanitize_text_field( $input['label'] ?? $key ), 'type' => $type, 'scope' => $scope, 'access' => 'location' === $scope && in_array( $input['access'] ?? '', array( 'all', 'selected', 'none' ), true ) ? $input['access'] : 'none', 'sites' => 'location' === $scope ? self::site_ids( $input['sites'] ?? array() ) : array(), 'required' => ! empty( $input['required'] ), 'clone' => ! empty( $input['clone'] ) );
		// Identifiers (practice code, content master) are indexed so imports and purges can find a site by value.
		$d['identifier'] = ! empty( $input['identifier'] ) && in_array( $type, array( 'text', 'number', 'email', 'phone', 'url' ), true );
		$d['help'] = mb_substr( sanitize_text_field( (string) ( $input['help'] ?? '' ) ), 0, 200 );
		$d['group'] = mb_substr( sanitize_text_field( (string) ( $input['group'] ?? '' ) ), 0, 40 );
		$d['options'] = self::options( $type, $input['options'] ?? array() );
		if ( 'embed' === $type ) {
			// Embeds print raw markup on every targeted site, so they are always network-locked and need unfiltered_html.
			if ( ! current_user_can( 'unfiltered_html' ) ) { throw new \InvalidArgumentException( 'Embed code needs permission to add unfiltered HTML.' ); }
			$d = array_merge( $d, array( 'scope' => 'network', 'access' => 'none', 'sites' => array(), 'required' => false, 'clone' => false ) );
			$d['placement'] = isset( self::PLACEMENTS[ $input['placement'] ?? '' ] ) ? $input['placement'] : 'head';
			$d['targets'] = self::site_ids( $input['targets'] ?? array() );
			$d['segment'] = sanitize_key( (string) ( $input['segment'] ?? '' ) );
			if ( '' !== $d['segment'] && ! isset( Fleet_Segments::all()[ $d['segment'] ] ) ) { throw new \InvalidArgumentException( 'Choose an existing segment.' ); }
		}
		foreach ( array_merge( $d['sites'], $d['targets'] ?? array() ) as $id ) { if ( ! self::site( $id ) ) { throw new \InvalidArgumentException( 'Selected locations must belong to this network.' ); } }
		$d['default'] = self::clean( $input['default'] ?? '', $d );
		if ( 'network' === $d['scope'] && $d['required'] && '' === $d['default'] ) { throw new \InvalidArgumentException( 'Required network variables need a default.' ); }
		$d['segment_defaults'] = array();
		if ( 'embed' !== $type ) {
			$segments = Fleet_Segments::all();
			foreach ( (array) ( $input['segment_defaults'] ?? array() ) as $slug => $value ) {
				if ( ! is_string( $value ) || '' === $value ) { continue; }
				if ( ! isset( $segments[ $slug ] ) ) { throw new \InvalidArgumentException( 'Unknown segment: ' . sanitize_key( (string) $slug ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped by the UI handler.
				// A segment defined by this variable cannot also give it a default: that would be circular.
				if ( isset( $segments[ $slug ]['rules'][ $key ] ) ) { throw new \InvalidArgumentException( 'A segment cannot set a default for a variable it is defined by.' ); }
				$d['segment_defaults'][ $slug ] = self::clean( $value, $d );
			}
		}
		return $d;
	}

	private static function site_ids( $ids ): array {
		return array_values( array_unique( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : preg_split( '/[\s,]+/', (string) $ids ) ) ) ) );
	}
	/** Dropdown choices (one per line), or the yes / no labels for a toggle. */
	private static function options( string $type, $input ): array {
		if ( ! in_array( $type, array( 'select', 'toggle' ), true ) ) { return array(); }
		$lines = is_array( $input ) ? $input : preg_split( '/\R/', (string) $input );
		$options = array_values( array_unique( array_filter( array_map( static fn( $o ) => mb_substr( sanitize_text_field( (string) $o ), 0, 100 ), $lines ), 'strlen' ) ) );
		if ( 'toggle' === $type ) { return array( $options[0] ?? 'Yes', $options[1] ?? 'No' ); }
		if ( ! $options || count( $options ) > 50 ) { throw new \InvalidArgumentException( 'List 1 to 50 dropdown options, one per line.' ); }
		return $options;
	}
	/** One item per line (or "a | b" from a spreadsheet cell): up to 100 plain-text items. */
	private static function list_items( string $value ): string {
		$items = preg_split( str_contains( $value, "\n" ) ? '/\R/' : '/\s*\|\s*/', $value );
		$items = array_values( array_filter( array_map( static fn( $item ) => mb_substr( sanitize_text_field( $item ), 0, 200 ), $items ), 'strlen' ) );
		if ( count( $items ) > 100 ) { throw new \InvalidArgumentException( 'A list can have up to 100 items.' ); }
		return implode( "\n", $items );
	}
	/** List items from a stored list value. */
	public static function items( string $value ): array { return '' === $value ? array() : explode( "\n", $value ); }
	/** What a token shows: the stored value, formatted for toggles, dates, hours and lists. */
	public static function display( array $d, string $value ): string {
		if ( 'hours' === $d['type'] ) { return Fleet_Hours::display( $value ); }
		if ( 'list' === $d['type'] ) { return implode( ', ', self::items( $value ) ); }
		if ( 'toggle' === $d['type'] && '' !== $value ) { $labels = self::options( 'toggle', $d['options'] ?? array() ); return '1' === $value ? $labels[0] : $labels[1]; }
		if ( 'date' === $d['type'] && '' !== $value ) { $time = strtotime( $value . ' 12:00:00 UTC' ); return $time ? wp_date( (string) get_option( 'date_format' ), $time ) : $value; }
		return $value;
	}

	public static function meta_keys(): array {
		return array_values( array_filter( (array) get_network_option( get_current_network_id(), self::META_KEYS, array() ), 'is_string' ) );
	}
	/** Custom field keys are network configuration: up to 50 plain keys, no serialized or protected-looking values. */
	public static function save_meta_keys( string $input ): array {
		if ( ! self::can( 'manage' ) ) { throw new \RuntimeException( 'Network administrator permission required.' ); }
		$keys = array_values( array_unique( array_filter( array_map( 'trim', preg_split( '/[\s,]+/', $input ) ) ) ) );
		foreach ( $keys as $key ) { if ( ! preg_match( '/^[A-Za-z0-9_\-]{1,191}$/D', $key ) ) { throw new \InvalidArgumentException( 'Custom field keys may use letters, numbers, - and _ only.' ); } }
		if ( count( $keys ) > 50 ) { throw new \InvalidArgumentException( 'Choose up to 50 custom fields.' ); }
		update_network_option( get_current_network_id(), self::META_KEYS, $keys );
		self::audit( 'meta-keys', 0, array() );
		Fleet_Cache::schedule();
		return $keys;
	}

	public static function profile( int $site ): array {
		$p = get_blog_option( $site, self::PROFILE, array() );
		return is_array( $p ) ? $p : array();
	}

	public static function editable( array $d, int $site ): bool {
		return 'location' === $d['scope'] && ( 'all' === $d['access'] || ( 'selected' === $d['access'] && in_array( $site, $d['sites'], true ) ) );
	}

	/**
	 * A site's value and where it came from: the site's own value, then its main data site,
	 * then the first matching segment default (in segment order), then the network default.
	 * Network-scope values skip the first two. $segments is false only while segments
	 * themselves are being evaluated, so membership never depends on a segment default.
	 */
	public static function resolved( int $site, string $key, bool $segments = true ): array {
		$memo = $site . ':' . $key . ':' . (int) $segments;
		if ( isset( self::$memo['resolved'][ $memo ] ) ) { return self::$memo['resolved'][ $memo ]; }
		return self::$memo['resolved'][ $memo ] = self::resolve( $site, $key, $segments );
	}
	private static function resolve( int $site, string $key, bool $segments ): array {
		$d = self::definitions()[ $key ] ?? null;
		if ( ! $d ) { return array( 'value' => '', 'source' => 'undefined' ); }
		$p = self::profile( $site );
		$fallback = static function () use ( $d, $site, $segments ): array {
			if ( $segments && self::profile( $site ) ) {
				foreach ( array_keys( Fleet_Segments::all() ) as $slug ) {
					if ( array_key_exists( $slug, (array) ( $d['segment_defaults'] ?? array() ) ) && Fleet_Segments::matches( $site, $slug ) ) { return array( 'value' => $d['segment_defaults'][ $slug ], 'source' => 'segment ' . $slug ); }
				}
			}
			return array( 'value' => $d['default'], 'source' => 'network' );
		};
		if ( 'network' === $d['scope'] ) { return $fallback(); }
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
		return $fallback();
	}

	/** Patch uses null to reset inheritance and an empty string as an explicit blank. */
	public static function save_profile( int $site, array $patch, ?array $connections = null, string $expected = '', bool $preview = false ): array {
		return self::locked( 'site_' . $site, static fn() => self::write_profile( $site, $patch, $connections, $expected, $preview ) );
	}

	private static function write_profile( int $site, array $patch, ?array $connections, string $expected, bool $preview ): array {
		if ( ! self::site( $site ) || ( ! self::can( 'values' ) && ! ( self::$importing && self::can( 'bulk' ) ) && ! current_user_can_for_site( $site, 'manage_options' ) ) ) { throw new \RuntimeException( 'You cannot manage this site.' ); }
		$old = self::profile( $site );
		if ( '' !== $expected && ! hash_equals( $expected, self::hash( $old ) ) ) { throw new \RuntimeException( 'Site changed since preview; refresh before saving.' ); }
		$p = $old;
		if ( null !== $connections ) {
			if ( ! self::can( 'manage' ) ) { throw new \RuntimeException( 'Only fleet managers can change connections.' ); }
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
			if ( 'imported' === $defs[ $key ]['scope'] && ! self::$importing ) { throw new \InvalidArgumentException( 'This variable comes from an import and cannot be edited here: ' . $key ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception is escaped by UI handler; API returns structured errors.
			if ( ! self::$importing && ! self::can( 'values' ) && ! self::editable( $defs[ $key ], $site ) ) { throw new \RuntimeException( 'This variable is controlled by the network: ' . $key ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception is escaped by UI handler; API returns structured errors.
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
		update_site_meta( $site, self::MANAGED, 1 );
		self::flush();
		Fleet_Segments::reindex_site( $site, true );
		// Encode relationship IDs in indexed meta keys; never filter unindexed meta_value.
		foreach ( array( 'group', 'source' ) as $relation ) {
			$previous = (int) ( $old[ $relation . '_id' ] ?? 0 );
			$current = (int) ( $p[ $relation . '_id' ] ?? 0 );
			if ( $previous && $previous !== $current ) { delete_site_meta( $site, 'brand_fleet_' . $relation . '_' . $previous ); }
			if ( $current ) { update_site_meta( $site, 'brand_fleet_' . $relation . '_' . $current, 1 ); }
		}
		Fleet_History::record( $site, (array) ( $old['values'] ?? array() ), (array) ( $p['values'] ?? array() ) );
		Fleet_Overrides::index_site( $site, $p, $old );
		self::audit( 'site', $site, array_keys( $patch ) );
		if ( ! self::$bulk ) { Fleet_Cache::schedule( $site ); }
		return $p;
	}

	/** One-time index backfill for profiles created before managed-first listing, resumable across requests so large networks never time out. */
	public static function index_managed_sites( int $limit = 200 ): void {
		$network = get_current_network_id();
		if ( get_network_option( $network, 'brand_fleet_managed_index', false ) ) { return; }
		$offset = (int) get_network_option( $network, 'brand_fleet_managed_index_offset', 0 ); $stop = $offset + $limit;
		do {
			$ids = get_sites( array( 'network_id' => $network, 'number' => 100, 'offset' => $offset, 'fields' => 'ids', 'orderby' => 'id', 'order' => 'ASC' ) );
			foreach ( $ids as $id ) { if ( self::profile( (int) $id ) ) { update_site_meta( (int) $id, self::MANAGED, 1 ); } }
			$offset += 100;
		} while ( count( $ids ) === 100 && $offset < $stop );
		if ( count( $ids ) === 100 ) { update_network_option( $network, 'brand_fleet_managed_index_offset', $offset ); return; }
		update_network_option( $network, 'brand_fleet_managed_index', true );
		delete_network_option( $network, 'brand_fleet_managed_index_offset' );
	}
	/** Managed sites first, then unenrolled, each by site ID. Queried per state so sorting applies before pagination. */
	public static function listed_sites( string $search, int $page ): array {
		self::index_managed_sites();
		$args = array( 'network_id' => get_current_network_id(), 'orderby' => 'id', 'order' => 'ASC', 'deleted' => 0, 'archived' => 0, 'spam' => 0 );
		if ( $search ) { $args['search'] = '*' . $search . '*'; }
		$managed = array( array( 'key' => self::MANAGED, 'compare' => 'EXISTS' ) );
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed managed-key existence; no unindexed meta_value comparison.
		$count = (int) get_sites( array_merge( $args, array( 'count' => true, 'meta_query' => $managed ) ) );
		$offset = ( max( 1, $page ) - 1 ) * 25;
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed managed-key existence; no unindexed meta_value comparison.
		$sites = $offset < $count ? get_sites( array_merge( $args, array( 'number' => 26, 'offset' => $offset, 'meta_query' => $managed ) ) ) : array();
		if ( count( $sites ) < 26 ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed managed-key existence; no unindexed meta_value comparison.
			$sites = array_merge( $sites, get_sites( array_merge( $args, array( 'number' => 26 - count( $sites ), 'offset' => max( 0, $offset - $count ), 'meta_query' => array( array( 'key' => self::MANAGED, 'compare' => 'NOT EXISTS' ) ) ) ) ) );
		}
		return $sites;
	}
	/** Run $callback holding a named network lock, waiting up to $wait tenths of a second for it. */
	public static function locked( string $name, callable $callback, int $wait = 0 ) {
		$key = 'brand_fleet_lock_' . sanitize_key( $name );
		for ( $try = 0; ! self::acquire( $key ); ++$try ) {
			if ( $try >= $wait ) { throw new \RuntimeException( 'Another update is running. Retry shortly.', self::BUSY ); }
			usleep( 100000 );
		}
		try { return $callback(); } finally { self::release( $key ); }
	}
	/** Memcached add() is atomic, so two requests can never both take a lock; locks expire after five minutes. */
	private static function acquire( string $key ): bool {
		if ( wp_using_ext_object_cache() ) { return wp_cache_add( $key, time(), 'brand_fleet_locks', 300 ); }
		$main = (int) get_main_site_id();
		$old = (int) get_blog_option( $main, $key, 0 );
		if ( $old && $old < time() - 300 ) { delete_blog_option( $main, $key ); }
		return add_blog_option( $main, $key, time() );
	}
	private static function release( string $key ): void {
		if ( wp_using_ext_object_cache() ) { wp_cache_delete( $key, 'brand_fleet_locks' ); return; }
		delete_blog_option( (int) get_main_site_id(), $key );
	}

	public static function hash( array $value ): string { return hash( 'sha256', wp_json_encode( $value ) ); }
	public static function audit( string $action, int $site, array $keys ): void {
		if ( self::$bulk ) { return; }
		self::record_activity( array( 'time' => gmdate( 'c' ), 'user' => get_current_user_id(), 'action' => $action, 'site' => $site, 'keys' => $keys ) );
	}
	public static function audit_job( array $job ): void {
		$counts = Fleet_Jobs::counts( $job );
		$details = $job['sample'] ?? array();
		self::record_activity( array( 'batch' => $job['id'], 'kind' => $job['kind'] ?? 'bulk', 'label' => Fleet_Undo::label( $job ), 'time' => gmdate( 'c' ), 'user' => get_current_user_id(), 'action' => 'bulk', 'keys' => array_keys( $job['patch'] ), 'total' => count( $job['ids'] ), 'updated' => $counts['updated'] ?? 0, 'skipped' => $counts['skipped'] ?? 0, 'phase' => $job['phase'], 'details' => $details ) + ( isset( $job['replace'] ) ? array( 'replace' => array_intersect_key( $job['replace'], array( 'find' => 1, 'with' => 1 ) ) ) : array() ) );
	}
	private static function record_activity( array $entry ): void {
		self::locked( 'audit', static function () use ( $entry ) {
			$log = (array) get_network_option( get_current_network_id(), 'brand_fleet_audit', array() );
			if ( isset( $entry['batch'] ) ) {
				$log = array_values( array_filter( $log, static fn( $row ) => ( $row['batch'] ?? '' ) !== $entry['batch'] ) );
			}
			$log[] = $entry;
			update_network_option( get_current_network_id(), 'brand_fleet_audit', array_slice( $log, -100 ) );
		}, 20 );
	}
}
