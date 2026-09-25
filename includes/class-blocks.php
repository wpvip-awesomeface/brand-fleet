<?php
/**
 * Registers the three blocks. Build-free by design (no wp-scripts / npm step
 * on the demo network): editor scripts are hand-authored plain JS with deps
 * declared here, and all blocks are server-rendered via render.php.
 */

namespace BrandFleet;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Blocks {

	private const BLOCKS = array( 'brand-header', 'brand-footer', 'shared-content' );

	public function init(): void {
		add_action( 'init', array( $this, 'register' ) );
		add_filter( 'block_categories_all', array( $this, 'category' ) );
	}

	public function category( array $categories ): array {
		$categories[] = array(
			'slug'  => 'brand-fleet',
			'title' => __( 'Brand Fleet', 'brand-fleet' ),
			'icon'  => 'networking',
		);
		return $categories;
	}

	public function register(): void {
		$editor_deps = array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' );

		foreach ( self::BLOCKS as $block ) {
			$dir = BRAND_FLEET_DIR . 'blocks/' . $block;

			wp_register_script(
				"brand-fleet-{$block}-editor",
				BRAND_FLEET_URL . "blocks/{$block}/editor.js",
				$editor_deps,
				BRAND_FLEET_VERSION,
				true
			);

			if ( file_exists( $dir . '/style.css' ) ) {
				wp_register_style(
					"brand-fleet-{$block}-style",
					BRAND_FLEET_URL . "blocks/{$block}/style.css",
					array(),
					BRAND_FLEET_VERSION
				);
			}

			register_block_type( $dir );
		}
	}
}
