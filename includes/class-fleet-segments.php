<?php
/**
 * Segments: named groups of sites defined by their dropdown and yes / no values
 * (for example province = ON, language = fr). Segments target bulk updates, find and
 * replace, embeds and shared content, and give variables per-segment defaults.
 *
 * Membership is computed from each site's resolved values without segment defaults, so
 * a segment can never depend on itself. Bulk targeting reads an index of indexed
 * site-meta keys (brand_fleet_ix_{key}_{hash}), the same indexed-key pattern the plugin
 * uses for hubs and data sources; while the index rebuilds, targeting scans profiles.
 */
namespace BrandFleet;

defined( 'ABSPATH' ) || exit;
class Fleet_Segments {
	const OPTION = 'brand_fleet_segments';
	const PREFIX = 'brand_fleet_ix_';
	/** Network option holding the resumable full-rebuild offset; absent when the index is current. */
	const DIRTY = 'brand_fleet_ix_rebuild';
	const MAX = 50;

	private static array $memo = array();
	public static function flush(): void { self::$memo = array(); }

	/** Segments in priority order: slug => [ label, rules => [ key => [ values ] ] ]. */
	public static function all(): array {
		if ( ! isset( self::$memo['all'] ) ) {
			$saved = get_network_option( get_current_network_id(), self::OPTION, array() );
			self::$memo['all'] = is_array( $saved ) ? $saved : array();
		}
		return self::$memo['all'];
	}

	/** Variables a segment rule can use: a fixed set of choices. */
	public static function rule_keys(): array {
		return array_keys( array_filter( Fleet::definitions(), static fn( $d ) => in_array( $d['type'], array( 'select', 'toggle' ), true ) ) );
	}

	/** Save the whole ordered list at once; order is priority for segment defaults. */
	public static function save( array $input, string $expected = '' ): array {
		return Fleet::locked( 'schema', static function () use ( $input, $expected ) {
			if ( ! Fleet::can( 'manage' ) ) { throw new \RuntimeException( 'Fleet manager permission required.' ); }
			if ( '' !== $expected && ! hash_equals( $expected, Fleet::hash( self::all() ) ) ) { throw new \RuntimeException( 'Segments changed; reload before saving.' ); }
			$defs = Fleet::definitions(); $keys = self::rule_keys(); $out = array();
			foreach ( $input as $slug => $segment ) {
				$slug = sanitize_key( (string) ( $segment['slug'] ?? $slug ) );
				if ( ! preg_match( '/^[a-z][a-z0-9_-]{0,39}$/D', $slug ) ) { throw new \InvalidArgumentException( 'Segment keys use lowercase letters, numbers, - and _, up to 40 characters.' ); }
				$rules = array();
				foreach ( (array) ( $segment['rules'] ?? array() ) as $key => $values ) {
					$values = array_values( array_unique( array_filter( array_map( 'strval', (array) $values ), 'strlen' ) ) );
					if ( ! $values ) { continue; }
					if ( ! in_array( $key, $keys, true ) ) { throw new \InvalidArgumentException( 'Segments use dropdown or yes / no variables only.' ); }
					foreach ( $values as $value ) { Fleet::clean( $value, $defs[ $key ] ); }
					$rules[ $key ] = $values;
				}
				if ( ! $rules ) { throw new \InvalidArgumentException( 'Give each segment at least one rule.' ); }
				$out[ $slug ] = array( 'label' => mb_substr( sanitize_text_field( (string) ( $segment['label'] ?? $slug ) ), 0, 80 ), 'rules' => $rules );
			}
			if ( count( $out ) > self::MAX ) { throw new \InvalidArgumentException( 'Up to 50 segments per network.' ); }
			// A removed segment must not leave defaults behind that would silently stop applying.
			foreach ( $defs as $key => $d ) {
				foreach ( array_keys( (array) ( $d['segment_defaults'] ?? array() ) ) as $slug ) {
					if ( ! isset( $out[ $slug ] ) ) { throw new \InvalidArgumentException( sprintf( 'Segment "%s" still gives %s a value. Remove that value first.', $slug, $key ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped by the UI handler.
					if ( isset( $out[ $slug ]['rules'][ $key ] ) ) { throw new \InvalidArgumentException( 'A segment cannot use a variable it also gives a default to.' ); }
				}
			}
			update_network_option( get_current_network_id(), self::OPTION, $out );
			Fleet::flush();
			self::reindex_all();
			Fleet::audit( 'segments', 0, array_keys( $out ) );
			Fleet_Cache::schedule();
			return $out;
		} );
	}

	/** Whether an enrolled site is in a segment. Every rule must match; any listed value matches a rule. */
	public static function matches( int $site, string $slug ): bool {
		$memo = $site . ':' . $slug;
		if ( isset( self::$memo['match'][ $memo ] ) ) { return self::$memo['match'][ $memo ]; }
		$segment = self::all()[ $slug ] ?? null; $in = (bool) $segment && (bool) Fleet::profile( $site );
		foreach ( $in ? $segment['rules'] : array() as $key => $values ) {
			if ( ! in_array( (string) Fleet::resolved( $site, $key, false )['value'], $values, true ) ) { $in = false; break; }
		}
		return self::$memo['match'][ $memo ] = $in;
	}

	/** Segment slugs the site belongs to. */
	public static function of( int $site ): array {
		return array_values( array_filter( array_keys( self::all() ), static fn( $slug ) => self::matches( $site, $slug ) ) );
	}

	/** Variables written to the index: rule variables (no segment defaults) and identifiers (fully resolved). */
	private static function indexed(): array {
		$out = array_fill_keys( self::rule_keys(), false );
		foreach ( Fleet::definitions() as $key => $d ) { if ( ! empty( $d['identifier'] ) || 'content_master' === $key ) { $out[ $key ] = true; } }
		return $out;
	}
	private static function meta_key( string $key, string $value ): string { return self::PREFIX . $key . '_' . substr( md5( $value ), 0, 12 ); }

	/** Rewrite one site's index rows; $dependents also refreshes sites that inherit from it. */
	public static function reindex_site( int $site, bool $dependents = false ): void {
		$want = array();
		if ( Fleet::profile( $site ) ) {
			foreach ( self::indexed() as $key => $full ) {
				$value = (string) Fleet::resolved( $site, $key, $full )['value'];
				if ( '' !== $value ) { $want[ self::meta_key( $key, $value ) ] = true; }
			}
		}
		foreach ( array_keys( (array) get_site_meta( $site ) ) as $meta ) {
			if ( str_starts_with( (string) $meta, self::PREFIX ) && ! isset( $want[ $meta ] ) ) { delete_site_meta( $site, $meta ); }
		}
		foreach ( array_keys( $want ) as $meta ) { update_site_meta( $site, $meta, 1 ); }
		if ( ! $dependents ) { return; }
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Exact relationship-key lookup uses the core blogmeta meta_key index; no meta_value comparison.
		$children = get_sites( array( 'network_id' => get_current_network_id(), 'number' => 51, 'fields' => 'ids', 'meta_key' => 'brand_fleet_source_' . $site ) );
		if ( count( $children ) > 50 ) { self::reindex_all(); return; }
		foreach ( $children as $child ) { self::reindex_site( (int) $child ); }
	}

	/** Mark the whole index for a background rebuild (definitions or segments changed). */
	public static function reindex_all(): void {
		update_network_option( get_current_network_id(), self::DIRTY, 0 );
		Fleet_Jobs::wake();
	}
	public static function pending(): bool { return false !== get_network_option( get_current_network_id(), self::DIRTY, false ); }

	/** Background rebuild step, resumable across ticks. */
	public static function rebuild( float $seconds ): void {
		$network = get_current_network_id();
		$offset = get_network_option( $network, self::DIRTY, false );
		if ( false === $offset ) { return; }
		$until = microtime( true ) + $seconds; $offset = (int) $offset;
		do {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed managed-key existence; no unindexed meta_value comparison.
			$ids = get_sites( array( 'network_id' => $network, 'number' => 100, 'offset' => $offset, 'fields' => 'ids', 'orderby' => 'id', 'order' => 'ASC', 'meta_query' => array( array( 'key' => Fleet::MANAGED, 'compare' => 'EXISTS' ) ) ) );
			foreach ( $ids as $id ) { self::reindex_site( (int) $id ); }
			$offset += 100;
			update_network_option( $network, self::DIRTY, $offset );
		} while ( count( $ids ) === 100 && microtime( true ) < $until );
		if ( count( $ids ) < 100 ) { delete_network_option( $network, self::DIRTY ); }
	}

	/** Enrolled sites whose indexed value for $key is one of $values. */
	public static function sites_where( string $key, array $values ): array {
		$ids = array();
		foreach ( $values as $value ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Exact index-key lookup uses the core blogmeta meta_key index; no meta_value comparison.
			$ids = array_merge( $ids, get_sites( array( 'network_id' => get_current_network_id(), 'number' => 10001, 'fields' => 'ids', 'deleted' => 0, 'archived' => 0, 'spam' => 0, 'meta_key' => self::meta_key( $key, (string) $value ) ) ) );
		}
		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	/** Every enrolled, active site in a segment. Falls back to a full scan while the index rebuilds. */
	public static function sites( string $slug ): array {
		$segment = self::all()[ $slug ] ?? null;
		if ( ! $segment ) { throw new \InvalidArgumentException( 'Unknown segment.' ); }
		if ( self::pending() ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed managed-key existence; no unindexed meta_value comparison.
			$all = get_sites( array( 'network_id' => get_current_network_id(), 'number' => 10001, 'fields' => 'ids', 'deleted' => 0, 'archived' => 0, 'spam' => 0, 'meta_query' => array( array( 'key' => Fleet::MANAGED, 'compare' => 'EXISTS' ) ) ) );
			return array_values( array_filter( array_map( 'intval', $all ), static fn( $id ) => self::matches( $id, $slug ) ) );
		}
		$ids = null;
		foreach ( $segment['rules'] as $key => $values ) {
			$match = self::sites_where( $key, $values );
			$ids = null === $ids ? $match : array_values( array_intersect( $ids, $match ) );
		}
		return $ids ?? array();
	}

	/** Sites whose identifier variable equals $value (for imports matching by practice code, and content masters). */
	public static function sites_with( string $key, string $value ): array {
		if ( empty( Fleet::definitions()[ $key ]['identifier'] ) && 'content_master' !== $key ) { throw new \InvalidArgumentException( 'Only identifier variables can be matched.' ); }
		if ( ! self::pending() ) { return self::sites_where( $key, array( $value ) ); }
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed managed-key existence; no unindexed meta_value comparison.
		$all = get_sites( array( 'network_id' => get_current_network_id(), 'number' => 10001, 'fields' => 'ids', 'deleted' => 0, 'archived' => 0, 'spam' => 0, 'meta_query' => array( array( 'key' => Fleet::MANAGED, 'compare' => 'EXISTS' ) ) ) );
		return array_values( array_filter( array_map( 'intval', $all ), static fn( $id ) => (string) Fleet::resolved( $id, $key )['value'] === $value ) );
	}
}
