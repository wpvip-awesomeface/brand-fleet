<?php
/**
 * Spreadsheet import: per-site values from a CSV (pasted, or a published Google Sheet
 * link), matched to sites by site ID, site address or an identifier variable such as a
 * practice code. Every import is a reviewed batch: preview first, then apply. A daily
 * scheduled import can apply on its own; it still runs as the person who set it up and
 * stops if they lose permission. Imported-scope variables can only be written here.
 */
namespace BrandFleet;

defined( 'ABSPATH' ) || exit;
class Fleet_Import {
	const OPTION = 'brand_fleet_import';
	const HOOK = 'brand_fleet_import_daily';
	const MAX_BYTES = 5242880;
	const MAX_ROWS = 10000;
	/** Problems kept per import; the count of further problems is still reported. */
	const MAX_PROBLEMS = 50;

	public function init(): void {
		add_action( self::HOOK, array( self::class, 'run_scheduled' ) );
	}

	public static function settings(): array {
		return (array) get_network_option( get_current_network_id(), self::OPTION, array() ) + array( 'url' => '', 'match' => 'site_id', 'auto' => false, 'owner' => 0, 'last' => array() );
	}

	/** Columns that can identify a site. */
	public static function match_options(): array {
		$out = array( 'site_id' => 'Site ID', 'domain' => 'Site address (domain and path)' );
		foreach ( Fleet::definitions() as $key => $d ) { if ( ! empty( $d['identifier'] ) ) { $out[ $key ] = $d['label'] . ' ({{' . $key . '}})'; } }
		return $out;
	}

	/** Admin form handler: returns extra query args for the redirect. */
	public static function handle( string $task, array $in ): array {
		$match = sanitize_key( (string) ( $in['match'] ?? 'site_id' ) );
		if ( ! isset( self::match_options()[ $match ] ) ) { throw new \InvalidArgumentException( 'Choose how to match rows to sites.' ); }
		$url = trim( (string) ( $in['url'] ?? '' ) );
		if ( 'import-settings' === $task ) {
			self::save_settings( $url, $match, ! empty( $in['auto'] ) );
			return array( 'saved' => 1 );
		}
		$csv = (string) ( $in['csv'] ?? '' );
		$j = self::start( '' !== trim( $csv ) ? $csv : self::fetch( $url ), $match, '' !== trim( $csv ) ? 'Pasted CSV' : $url );
		return array( 'batch' => $j['id'] );
	}

	public static function save_settings( string $url, string $match, bool $auto ): void {
		if ( ! Fleet::can( 'bulk' ) ) { throw new \RuntimeException( 'Bulk update permission required.' ); }
		if ( '' !== $url ) { self::check_url( $url ); }
		if ( $auto && '' === $url ) { throw new \InvalidArgumentException( 'A daily import needs a spreadsheet link.' ); }
		$settings = array( 'url' => $url, 'match' => $match, 'auto' => $auto, 'owner' => get_current_user_id() ) + self::settings();
		update_network_option( get_current_network_id(), self::OPTION, $settings );
		$main = (int) get_main_site_id();
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- The schedule lives on the main site with the other fleet events; restored in finally.
		switch_to_blog( $main );
		try {
			wp_clear_scheduled_hook( self::HOOK );
			if ( $auto ) { wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK ); }
		} finally { restore_current_blog(); }
		Fleet::audit( 'import-settings', 0, array() );
	}

	private static function check_url( string $url ): void {
		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) || ! wp_http_validate_url( $url ) ) { throw new \InvalidArgumentException( 'Use a public https link, for example a Google Sheet published to the web as CSV.' ); }
	}

	public static function fetch( string $url ): string {
		self::check_url( $url );
		$args = array( 'timeout' => 10, 'limit_response_size' => self::MAX_BYTES, 'redirection' => 3 );
		// Admin and cron only, never on a visitor request, so the 10-second timeout cannot slow a page.
		$response = wp_safe_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) { throw new \RuntimeException( 'Could not download the spreadsheet: ' . $response->get_error_message() ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped by the UI handler.
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) { throw new \RuntimeException( 'The spreadsheet link answered with HTTP ' . (int) wp_remote_retrieve_response_code( $response ) . '. Check that it is published to the web.' ); }
		return (string) wp_remote_retrieve_body( $response );
	}

	/** Header row → list of rows keyed by column name. */
	public static function parse( string $csv ): array {
		if ( strlen( $csv ) > self::MAX_BYTES ) { throw new \InvalidArgumentException( 'The spreadsheet is larger than 5 MB.' ); }
		$csv = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $csv );
		if ( str_starts_with( ltrim( $csv ), '<' ) ) { throw new \InvalidArgumentException( 'That link returned a web page, not CSV. In Google Sheets use File → Share → Publish to web → CSV.' ); }
		$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory stream for fgetcsv; nothing touches disk.
		fwrite( $handle, $csv ); rewind( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		$header = null; $rows = array();
		while ( false !== ( $line = fgetcsv( $handle, 0, ',', '"', '' ) ) ) {
			if ( array( null ) === $line ) { continue; }
			$line = array_map( static fn( $cell ) => trim( (string) $cell ), $line );
			if ( null === $header ) { $header = array_map( static fn( $name ) => strtolower( str_replace( array( '{', '}', ' ' ), array( '', '', '_' ), $name ) ), $line ); continue; }
			if ( count( $rows ) >= self::MAX_ROWS ) { fclose( $handle ); throw new \InvalidArgumentException( 'Up to 10,000 rows per import.' ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$rows[] = array_combine( $header, array_pad( array_slice( $line, 0, count( $header ) ), count( $header ), '' ) );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( ! $header || ! $rows ) { throw new \InvalidArgumentException( 'The spreadsheet needs a header row and at least one row of values.' ); }
		return $rows;
	}

	/** Rows → site_id => patch, plus problems a person should see before applying. */
	public static function plan( array $rows, string $match ): array {
		$defs = Fleet::definitions(); $plan = array(); $problems = array(); $count = 0;
		$note = static function ( string $text ) use ( &$problems, &$count ) { ++$count; if ( count( $problems ) < self::MAX_PROBLEMS ) { $problems[] = $text; } };
		if ( ! array_key_exists( $match, $rows[0] ) ) { throw new \InvalidArgumentException( sprintf( 'The spreadsheet needs a "%s" column to match rows to sites.', $match ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped by the UI handler.
		$columns = array();
		foreach ( array_keys( $rows[0] ) as $column ) {
			if ( $column === $match || '' === $column ) { continue; }
			$d = $defs[ $column ] ?? null;
			if ( ! $d ) { $note( sprintf( 'Column "%s" is not a variable key, so it was ignored.', $column ) ); continue; }
			if ( 'network' === $d['scope'] || 'embed' === $d['type'] ) { $note( sprintf( 'Column "%s" is set by the network, not per site, so it was ignored.', $column ) ); continue; }
			$columns[] = $column;
		}
		if ( ! $columns ) { throw new \InvalidArgumentException( 'No column matches a per-site variable key. Use variable keys, such as phone_sales, as column headers.' ); }
		foreach ( $rows as $n => $row ) {
			$line = $n + 2; $value = (string) $row[ $match ];
			if ( '' === $value ) { $note( sprintf( 'Row %d has no %s, so it was skipped.', $line, $match ) ); continue; }
			$site = self::find_site( $match, $value );
			if ( is_string( $site ) ) { $note( sprintf( 'Row %d (%s): %s', $line, $value, $site ) ); continue; }
			if ( isset( $plan[ $site ] ) ) { $note( sprintf( 'Row %d (%s) matches the same site as an earlier row, so it was skipped.', $line, $value ) ); continue; }
			$patch = array();
			foreach ( $columns as $column ) {
				// An empty cell leaves the site's value as it is.
				if ( '' === $row[ $column ] ) { continue; }
				try { $patch[ $column ] = Fleet::clean( $row[ $column ], $defs[ $column ] ); } catch ( \InvalidArgumentException $e ) { $note( sprintf( 'Row %d (%s), %s: %s', $line, $value, $column, $e->getMessage() ) ); }
			}
			if ( $patch ) { $plan[ $site ] = $patch; }
		}
		return array( 'plan' => $plan, 'problems' => $problems, 'problem_count' => $count );
	}

	/** A site ID, or a reason it could not be matched. */
	private static function find_site( string $match, string $value ) {
		if ( 'site_id' === $match ) {
			$ids = ctype_digit( $value ) && Fleet::site( (int) $value ) ? array( (int) $value ) : array();
		} elseif ( 'domain' === $match ) {
			$parts = wp_parse_url( ( str_contains( $value, '://' ) ? '' : 'https://' ) . $value );
			$site = get_site_by_path( (string) ( $parts['host'] ?? '' ), (string) ( $parts['path'] ?? '/' ) );
			$ids = $site && Fleet::site( (int) $site->blog_id ) && trailingslashit( $site->path ) === trailingslashit( '/' . ltrim( (string) ( $parts['path'] ?? '/' ), '/' ) ) ? array( (int) $site->blog_id ) : array();
		} else {
			$ids = Fleet_Segments::sites_with( $match, $value );
		}
		if ( ! $ids ) { return 'no matching site.'; }
		if ( count( $ids ) > 1 ) { return 'more than one site has this value.'; }
		return Fleet::profile( $ids[0] ) ? $ids[0] : 'that site is not in the fleet yet.';
	}

	public static function start( string $csv, string $match, string $label, bool $auto = false ): array {
		if ( ! Fleet::can( 'bulk' ) ) { throw new \RuntimeException( 'Bulk update permission required.' ); }
		if ( ! isset( self::match_options()[ $match ] ) ) { throw new \InvalidArgumentException( 'Choose how to match rows to sites.' ); }
		$planned = self::plan( self::parse( $csv ), $match );
		if ( ! $planned['plan'] ) { throw new \InvalidArgumentException( 'Nothing to import. ' . implode( ' ', array_slice( $planned['problems'], 0, 5 ) ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped by the UI handler.
		return Fleet_Jobs::start_import( $planned['plan'], array( 'label' => mb_substr( $label, 0, 300 ), 'match' => $match, 'rows' => count( $planned['plan'] ), 'problems' => $planned['problems'], 'problem_count' => $planned['problem_count'] ), $auto );
	}

	/** Daily scheduled import, run as the person who turned it on. */
	public static function run_scheduled(): void {
		$settings = self::settings(); $previous = get_current_user_id();
		if ( empty( $settings['auto'] ) || ! $settings['url'] ) { return; }
		wp_set_current_user( (int) $settings['owner'] );
		try {
			if ( ! Fleet::can( 'bulk' ) ) { throw new \RuntimeException( 'The person who set up this import no longer has bulk update permission.' ); }
			$current = Fleet_Jobs::get();
			if ( in_array( $current['phase'] ?? '', array( 'preview', 'ready', 'apply' ), true ) ) { throw new \RuntimeException( 'Skipped: the owner has another batch open. Apply or discard it, and the next run will import.' ); }
			$j = self::start( self::fetch( $settings['url'] ), $settings['match'], $settings['url'], true );
			$last = array( 'time' => gmdate( 'c' ), 'status' => 'started', 'batch' => $j['id'], 'sites' => count( $j['ids'] ) );
		} catch ( \Throwable $e ) {
			$last = array( 'time' => gmdate( 'c' ), 'status' => 'failed', 'error' => $e->getMessage() );
		} finally {
			wp_set_current_user( $previous );
		}
		update_network_option( get_current_network_id(), self::OPTION, array( 'last' => $last ) + self::settings() );
	}
}
