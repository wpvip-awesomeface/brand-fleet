<?php
/** Bounded VIP edge invalidation for pages that include shared brand information. */
namespace BrandFleet;

defined( 'ABSPATH' ) || exit;
class Fleet_Cache {
	public static function schedule( int $site = 0 ): void {
		$args = array( get_current_network_id(), $site, 0, 0 );
		if ( ! wp_next_scheduled( 'brand_fleet_purge', $args ) ) { wp_schedule_single_event( time() + 1, 'brand_fleet_purge', $args ); }
	}
	public static function run( int $network, int $site, int $offset, int $post_offset ): void {
		if ( ! function_exists( 'wpvip_purge_edge_cache_for_post' ) ) { return; }
		$query = array( 'network_id' => $network, 'number' => 1, 'offset' => $offset, 'orderby' => 'id', 'order' => 'ASC', 'fields' => 'ids', 'deleted' => 0, 'archived' => 0, 'spam' => 0 );
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Exact relationship-key lookup uses the core blogmeta meta_key index; no meta_value comparison.
		if ( $site < 0 ) { $query['meta_key'] = 'brand_fleet_source_' . abs( $site ); }
		$sites = $site > 0 ? array( $site ) : get_sites( $query );
		if ( ! $sites ) { return; }
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- Database scope only; always restored in finally.
		switch_to_blog( (int) $sites[0] );
		try {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.get_posts_get_posts -- suppress_filters=false explicitly enables VIP query caching.
			$posts = get_posts( array( 'post_type' => array_values( get_post_types( array( 'public' => true ) ) ), 'suppress_filters' => false, 'post_status' => 'publish', 'numberposts' => 25, 'offset' => $post_offset, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids' ) );
			foreach ( $posts as $id ) { wpvip_purge_edge_cache_for_post( $id ); }
		} finally { restore_current_blog(); }
		if ( count( $posts ) === 25 ) { wp_schedule_single_event( time() + 1, 'brand_fleet_purge', array( $network, $site, $offset, $post_offset + 25 ) ); }
		elseif ( $site <= 0 ) { wp_schedule_single_event( time() + 1, 'brand_fleet_purge', array( $network, $site, $offset + 1, 0 ) ); }
		else { wp_schedule_single_event( time() + 1, 'brand_fleet_purge', array( $network, -$site, 0, 0 ) ); }
	}
}
