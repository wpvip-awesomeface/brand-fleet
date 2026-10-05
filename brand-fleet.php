<?php
/**
 * Plugin Name: Brand Fleet
 * Plugin URI:  https://github.com/wpvip-awesomeface/brand-fleet
 * Description: Network-managed brand variables, delegated location overrides, bulk updates, Add Site setup, and dynamic brand blocks.
 * Version:     1.1.0
 * Requires at least: 6.9
 * Requires PHP: 8.0
 * Network:      true
 * Author:      WordPress VIP Solutions Engineering
 * License:     GPL-2.0-or-later
 * Text Domain: brand-fleet
 *
 * ── Isolation model ──────────────────────────────────────────────────────
 * Network activation exposes a central registry and Add Site onboarding.
 * Individual sites opt in to managed inheritance; existing site options remain
 * readable for sites not yet enrolled. Definitions are scoped to one WP network.
 *
 * ── Cache posture ────────────────────────────────────────────────────────────────────────────
 * Every rendered surface varies ONLY by which site is being served — per-site
 * options resolve from the current blog, and cross-site content pulls are
 * deterministic per (master, slug). No cookies, no sessions, no per-visitor
 * state: on VIP the edge cache keys on host+path, so each brand site caches
 * at full hit rate.
 */

namespace BrandFleet;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BRAND_FLEET_VERSION', '1.1.0' );
define( 'BRAND_FLEET_DIR', plugin_dir_path( __FILE__ ) );
define( 'BRAND_FLEET_URL', plugin_dir_url( __FILE__ ) );

require_once BRAND_FLEET_DIR . 'includes/class-settings.php';
require_once BRAND_FLEET_DIR . 'includes/class-content.php';
require_once BRAND_FLEET_DIR . 'includes/class-blocks.php';
require_once BRAND_FLEET_DIR . 'includes/class-admin.php';
require_once BRAND_FLEET_DIR . 'includes/class-fleet.php';
require_once BRAND_FLEET_DIR . 'includes/class-fleet-jobs.php';
require_once BRAND_FLEET_DIR . 'includes/class-fleet-cache.php';
require_once BRAND_FLEET_DIR . 'includes/class-site-picker.php';
require_once BRAND_FLEET_DIR . 'includes/class-fleet-admin.php';
require_once BRAND_FLEET_DIR . 'includes/class-fleet-onboarding.php';
require_once BRAND_FLEET_DIR . 'includes/class-fleet-clone.php';
require_once BRAND_FLEET_DIR . 'includes/class-fleet-transfer.php';
require_once BRAND_FLEET_DIR . 'includes/class-fleet-abilities.php';
require_once BRAND_FLEET_DIR . 'includes/class-plugin.php';

// Expose this plugin's per-site options to the MCP update-site-option
// allowlist so demo sites can be configured over MCP. The filter is applied
// by WPVIP-Multisite-MCP-Abilities around its built-in allowlist.
add_filter(
	'vip_mcp_allowed_write_options',
	static function ( array $allowed ): array {
		return array_merge( $allowed, Settings::mcp_writable_keys() );
	}
);

add_action(
	'plugins_loaded',
	static function () {
		Plugin::get_instance();
	}
);
