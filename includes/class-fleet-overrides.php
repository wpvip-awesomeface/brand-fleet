<?php
/**
 * Overrides report: which sites set their own value for a variable instead of using
 * the shared one. Each own value is marked in indexed site meta (one key per
 * variable), so counts and lists stay fast on large networks. Profiles saved before
 * this index existed are indexed once, a few hundred sites per visit or cron tick.
 */
namespace BrandFleet;

defined( 'ABSPATH' ) || exit;
class Fleet_Overrides {
	const PREFIX = 'brand_fleet_own_';
	const DONE   = 'brand_fleet_own_index';
	const OFFSET = 'brand_fleet_own_index_offset';

	/** Keep one site's markers in step with its profile, touching only the keys that changed. */
	public static function index_site( int $site, array $profile, array $before = array() ): void {
		$now = array_keys( (array) ( $profile['values'] ?? array() ) );
		$was = array_keys( (array) ( $before['values'] ?? array() ) );
		foreach ( array_diff( $now, $was ) as $key ) {
			update_site_meta( $site, self::PREFIX . $key, 1 );
		}
		foreach ( array_diff( $was, $now ) as $key ) {
			delete_site_meta( $site, self::PREFIX . $key );
		}
	}

	/** Whether the per-site override index has been built. */
	public static function indexed(): bool {
		return (bool) get_network_option( get_current_network_id(), self::DONE, false );
	}
	/** Resumable backfill for profiles saved before this release. */
	public static function backfill( int $limit = 200 ): void {
		$network = get_current_network_id();
		if ( self::indexed() ) {
			return;
		}
		$offset = (int) get_network_option( $network, self::OFFSET, 0 );
		$stop   = $offset + $limit;
		do {
			// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed managed-key existence; no unindexed meta_value comparison.
			$ids = get_sites(
				array(
					'network_id' => $network,
					'number'     => 100,
					'offset'     => $offset,
					'fields'     => 'ids',
					'orderby'    => 'id',
					'order'      => 'ASC',
					'meta_query' => array(
						array(
							'key'     => Fleet::MANAGED,
							'compare' => 'EXISTS',
						),
					),
				)
			);
			// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			foreach ( $ids as $id ) {
				self::index_site( (int) $id, Fleet::profile( (int) $id ) );
			}
			$offset += 100;
		} while ( count( $ids ) === 100 && $offset < $stop );
		if ( count( $ids ) === 100 ) {
			update_network_option( $network, self::OFFSET, $offset );
			return;
		}
		update_network_option( $network, self::DONE, true );
		delete_network_option( $network, self::OFFSET );
	}

	private static function base(): array {
		return array(
			'network_id' => get_current_network_id(),
			'deleted'    => 0,
			'archived'   => 0,
			'spam'       => 0,
		);
	}
	/** Number of managed sites in the fleet. */
	public static function fleet_count(): int {
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed managed-key existence; no unindexed meta_value comparison.
		return (int) get_sites(
			self::base() + array(
				'count'      => true,
				'meta_query' => array(
					array(
						'key'     => Fleet::MANAGED,
						'compare' => 'EXISTS',
					),
				),
			)
		);
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	}
	/** Every fleet site ID, up to 10,000. */
	public static function fleet_sites(): array {
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed managed-key existence; no unindexed meta_value comparison.
		return array_map(
			'intval',
			get_sites(
				self::base() + array(
					'fields'     => 'ids',
					'number'     => 10000,
					'orderby'    => 'id',
					'order'      => 'ASC',
					'meta_query' => array(
						array(
							'key'     => Fleet::MANAGED,
							'compare' => 'EXISTS',
						),
					),
				)
			)
		);
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	}
	/** Number of fleet sites with their own value for $key. */
	public static function count( string $key ): int {
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Exact key lookup on the indexed blogmeta meta_key; no meta_value comparison.
		return (int) get_sites(
			self::base() + array(
				'count'    => true,
				'meta_key' => self::PREFIX . $key,
			)
		);
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	}
	/** Site IDs with their own value for $key: one page, or every one (up to 10,000) for a bulk reset. */
	public static function sites( string $key, int $page = 0 ): array {
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Exact key lookup on the indexed blogmeta meta_key; no meta_value comparison.
		$args = self::base() + array(
			'fields'   => 'ids',
			'orderby'  => 'id',
			'order'    => 'ASC',
			'meta_key' => self::PREFIX . $key,
			'number'   => $page ? 26 : 10000,
		);
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		if ( $page ) {
			$args['offset'] = ( $page - 1 ) * 25;
		}
		return array_map( 'intval', get_sites( $args ) );
	}

	/** Variables a report can cover: everything a site can hold its own value for. */
	public static function variables(): array {
		return array_filter( Fleet::definitions(), static fn( $d ) => 'network' !== $d['scope'] && 'embed' !== $d['type'] );
	}
}
