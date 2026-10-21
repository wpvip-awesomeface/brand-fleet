<?php
/**
 * Runs when Brand Fleet is deleted from Network Admin > Plugins.
 *
 * Always: stops Brand Fleet's scheduled events and clears in-flight batch state
 * (running jobs, purge queue, locks), so nothing keeps firing after removal.
 *
 * Brand data (definitions, segments, every site's values, history, undo data,
 * site settings) is kept by default: removing it changes what every enrolled
 * site prints and cannot be undone. To remove it too, define this in
 * wp-config.php (or vip-config.php) before deleting the plugin:
 *
 *     define( 'BRAND_FLEET_DELETE_DATA', true );
 *
 * Loaded as an mu-plugin (VIP client-mu-plugins), this file never runs; run it
 * with `wp eval-file uninstall.php` after defining WP_UNINSTALL_PLUGIN if needed.
 *
 * @package BrandFleet
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- One-time cleanup: key discovery by prefix has no API; every delete goes through the core API so caches are cleared.
// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- Per-site cleanup, always restored.

// Network option names (sitemeta) that start with a prefix, as [ network_id, meta_key ] pairs.
$brand_fleet_network_keys = static function ( string $prefix ): array {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT site_id, meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s", $wpdb->esc_like( $prefix ) . '%' ), ARRAY_N );
	return array_map( static fn( $row ) => array( (int) $row[0], (string) $row[1] ), (array) $rows );
};

// Option names on the current site that start with a prefix.
$brand_fleet_site_keys = static function ( string $prefix ): array {
	global $wpdb;
	return (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
};

$brand_fleet_delete_data = defined( 'BRAND_FLEET_DELETE_DATA' ) && BRAND_FLEET_DELETE_DATA;

// 1. Scheduled events, on every site that may hold them.
$brand_fleet_hooks = array( 'brand_fleet_tick', 'brand_fleet_import_daily', 'brand_fleet_purge' );
$brand_fleet_main  = (int) get_main_site_id();
switch_to_blog( $brand_fleet_main );
try {
	foreach ( $brand_fleet_hooks as $brand_fleet_hook ) {
		wp_unschedule_hook( $brand_fleet_hook );
	}
	// Locks only live in options when there is no persistent object cache.
	foreach ( $brand_fleet_site_keys( 'brand_fleet_lock_' ) as $brand_fleet_key ) {
		delete_option( $brand_fleet_key );
	}
} finally {
	restore_current_blog();
}

// 2. In-flight batch state at the network level.
$brand_fleet_transient = array( 'brand_fleet_job_', 'brand_fleet_active_jobs', 'brand_fleet_purge_queue', 'brand_fleet_ix_rebuild', 'brand_fleet_undo_cleanup', 'brand_fleet_managed_index_offset', 'brand_fleet_own_index_offset' );
foreach ( $brand_fleet_delete_data ? array( 'brand_fleet_', '_site_transient_brand_fleet_', '_site_transient_timeout_brand_fleet_' ) : $brand_fleet_transient as $brand_fleet_prefix ) {
	foreach ( $brand_fleet_network_keys( $brand_fleet_prefix ) as list( $brand_fleet_network, $brand_fleet_key ) ) {
		$brand_fleet_key = preg_replace( '/^_site_transient_(timeout_)?/', '', $brand_fleet_key, 1, $brand_fleet_was_transient );
		if ( $brand_fleet_was_transient ) {
			delete_site_transient( $brand_fleet_key );
		} else {
			delete_network_option( $brand_fleet_network, $brand_fleet_key );
		}
	}
}

if ( ! $brand_fleet_delete_data ) {
	return;
}

// 3. Brand data on every site: options, enrollment and segment meta, undo snapshots.
global $wpdb;
for ( $brand_fleet_offset = 0; ; $brand_fleet_offset += 100 ) {
	$brand_fleet_sites = get_sites( array( 'number' => 100, 'offset' => $brand_fleet_offset, 'fields' => 'ids', 'orderby' => 'id', 'order' => 'ASC' ) );
	foreach ( $brand_fleet_sites as $brand_fleet_site ) {
		$brand_fleet_site = (int) $brand_fleet_site;
		foreach ( array_keys( (array) get_site_meta( $brand_fleet_site ) ) as $brand_fleet_key ) {
			if ( str_starts_with( (string) $brand_fleet_key, 'brand_fleet_' ) ) {
				delete_site_meta( $brand_fleet_site, $brand_fleet_key );
			}
		}
		switch_to_blog( $brand_fleet_site );
		try {
			foreach ( $brand_fleet_site_keys( 'brand_fleet_' ) as $brand_fleet_key ) {
				delete_option( $brand_fleet_key );
			}
			$brand_fleet_meta = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_brand_fleet_undo_' ) . '%' ) );
			foreach ( $brand_fleet_meta as $brand_fleet_key ) {
				delete_post_meta_by_key( $brand_fleet_key );
			}
		} finally {
			restore_current_blog();
		}
	}
	if ( count( $brand_fleet_sites ) < 100 ) {
		break;
	}
}

wp_cache_flush_group( 'brand-fleet' );
