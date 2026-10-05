<?php
/**
 * Bounded VIP edge invalidation for pages that include shared brand information.
 *
 * Changes add sites to one deduplicated queue; the background tick drains it on a
 * time budget, so a 10,000-site batch costs a few cron runs instead of thousands of
 * single events. Definition changes purge enrolled sites only, never the whole network.
 */
namespace BrandFleet;

defined( 'ABSPATH' ) || exit;
class Fleet_Cache {
	const QUEUE = 'brand_fleet_purge_queue';
	const PAGE = 100;
	/** Posts purged per tick. */
	const MAX_POSTS = 2000;
	/**
	 * VIP sends at most 100 purge URLs per request and drops the rest; one post queues
	 * several URLs (permalink, home, archives, feeds). Flushing every few posts keeps
	 * each send under the cap.
	 */
	const FLUSH_EVERY = 10;

	/** Queue one site and the locations that read from it; 0 means every enrolled site. */
	public static function schedule( int $site = 0 ): void { self::queue( array( $site ) ); }

	public static function queue( array $sites ): void {
		if ( ! $sites || ! function_exists( 'wpvip_purge_edge_cache_for_post' ) ) { return; }
		Fleet::locked( 'purge', static function () use ( $sites ) {
			$q = self::get();
			foreach ( $sites as $site ) {
				if ( 0 === (int) $site ) { $q['all'] = 0; } else { $q['sites'][ abs( (int) $site ) ] = array( 0, true ); }
			}
			self::put( $q );
		}, 30 );
		Fleet_Jobs::wake();
	}
	public static function pending(): bool {
		$q = self::get();
		return null !== $q['all'] || $q['sites'] || $q['sources'];
	}
	private static function get(): array {
		return (array) get_network_option( get_current_network_id(), self::QUEUE, array() ) + array( 'all' => null, 'sites' => array(), 'sources' => array() );
	}
	private static function put( array $q ): void { update_network_option( get_current_network_id(), self::QUEUE, $q ); }

	/** Called only by the background tick, which Cron Control never runs twice at once. */
	public static function drain( float $seconds ): void {
		if ( ! function_exists( 'wpvip_purge_edge_cache_for_post' ) ) { return; }
		$until = microtime( true ) + $seconds; $posts = 0;
		while ( microtime( true ) < $until && $posts < self::MAX_POSTS ) {
			$work = Fleet::locked( 'purge', array( self::class, 'take' ), 30 );
			if ( ! $work ) { return; }
			$result = self::work( $work );
			$posts += $result['posts'];
			Fleet::locked( 'purge', static fn() => self::settle( $work, $result ), 30 );
		}
	}
	public static function take(): array {
		$q = self::get();
		foreach ( $q['sites'] as $id => $state ) { return array( 'type' => 'site', 'id' => (int) $id, 'offset' => (int) $state[0], 'deps' => ! empty( $state[1] ) ); }
		foreach ( $q['sources'] as $id => $offset ) { return array( 'type' => 'source', 'id' => (int) $id, 'offset' => (int) $offset ); }
		return null === $q['all'] ? array() : array( 'type' => 'all', 'id' => 0, 'offset' => (int) $q['all'] );
	}
	private static function work( array $work ): array {
		$query = array( 'network_id' => get_current_network_id(), 'number' => self::PAGE, 'offset' => $work['offset'], 'orderby' => 'id', 'order' => 'ASC', 'fields' => 'ids', 'deleted' => 0, 'archived' => 0, 'spam' => 0 );
		if ( 'source' === $work['type'] ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Exact relationship-key lookup uses the core blogmeta meta_key index; no meta_value comparison.
			$ids = get_sites( $query + array( 'meta_key' => 'brand_fleet_source_' . $work['id'] ) );
			return array( 'add' => $ids, 'more' => count( $ids ) === self::PAGE, 'posts' => 0 );
		}
		if ( 'all' === $work['type'] ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed managed-key existence; no unindexed meta_value comparison.
			$ids = get_sites( $query + array( 'meta_query' => array( array( 'key' => Fleet::MANAGED, 'compare' => 'EXISTS' ) ) ) );
			return array( 'add' => $ids, 'more' => count( $ids ) === self::PAGE, 'posts' => 0 );
		}
		if ( ! Fleet::site( $work['id'] ) ) { return array( 'add' => array(), 'more' => false, 'posts' => 0 ); }
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- Database scope only; always restored in finally.
		switch_to_blog( $work['id'] );
		try {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.get_posts_get_posts -- suppress_filters=false explicitly enables VIP query caching.
			$posts = get_posts( array( 'post_type' => array_values( get_post_types( array( 'public' => true ) ) ), 'suppress_filters' => false, 'post_status' => 'publish', 'numberposts' => self::PAGE, 'offset' => $work['offset'], 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids' ) );
			foreach ( $posts as $k => $id ) {
				wpvip_purge_edge_cache_for_post( $id );
				if ( 0 === ( $k + 1 ) % self::FLUSH_EVERY ) { self::flush(); }
			}
			self::flush();
		} finally { restore_current_blog(); }
		return array( 'add' => array(), 'more' => count( $posts ) === self::PAGE, 'posts' => count( $posts ) );
	}
	/** Send queued purges now instead of at shutdown, where anything past VIP's per-request cap is discarded. */
	private static function flush(): void {
		if ( class_exists( 'WPCOM_VIP_Cache_Manager' ) && method_exists( 'WPCOM_VIP_Cache_Manager', 'execute_purges' ) ) { \WPCOM_VIP_Cache_Manager::instance()->execute_purges(); }
	}
	/** Record progress unless the item was re-queued meanwhile; a re-queued site starts over. */
	private static function settle( array $work, array $result ): void {
		$q = self::get();
		foreach ( $result['add'] as $id ) { $q['sites'] += array( (int) $id => array( 0, false ) ); }
		$next = $work['offset'] + self::PAGE; $id = $work['id'];
		if ( 'site' === $work['type'] && ( (int) ( $q['sites'][ $id ][0] ?? -1 ) ) === $work['offset'] ) {
			if ( $result['more'] ) { $q['sites'][ $id ][0] = $next; } else {
				unset( $q['sites'][ $id ] );
				if ( $work['deps'] ) { $q['sources'] += array( $id => 0 ); }
			}
		} elseif ( 'source' === $work['type'] && ( $q['sources'][ $id ] ?? -1 ) === $work['offset'] ) {
			if ( $result['more'] ) { $q['sources'][ $id ] = $next; } else { unset( $q['sources'][ $id ] ); }
		} elseif ( 'all' === $work['type'] && $q['all'] === $work['offset'] ) {
			$q['all'] = $result['more'] ? $next : null;
		}
		self::put( $q );
	}

	/** Events scheduled by 1.0 drain into the queue. */
	public static function run( int $network = 0, int $site = 0 ): void { self::schedule( abs( $site ) ); }
}
