<?php
/**
 * Registers the brand blocks. Build-free by design (no wp-scripts / npm step
 * on the demo network): editor scripts are hand-authored plain JS with deps
 * declared here, and all blocks are server-rendered via render.php.
 */

namespace BrandFleet;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Blocks {
	private const BLOCKS = array( 'brand-header', 'brand-footer', 'shared-content', 'hours', 'list' );

	/** Hooks block registration, the block category and editor data. */
	public function init(): void {
		add_action( 'init', array( $this, 'register' ) );
		add_filter( 'block_categories_all', array( $this, 'category' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'editor_data' ) );
	}

	/** The variable a block shows: the chosen key when it has the right type, else the first variable of that type. */
	public static function variable( string $key, string $type ): string {
		$defs = Fleet::definitions();
		if ( isset( $defs[ $key ] ) && $type === $defs[ $key ]['type'] ) {
			return $key;
		}
		foreach ( $defs as $candidate => $d ) {
			if ( $type === $d['type'] ) {
				return (string) $candidate;
			}
		}
		return '';
	}
	/** This site's raw value for a variable; sites outside the fleet have none. */
	public static function value( string $key ): string {
		$site = get_current_blog_id();
		return Fleet::profile( $site ) ? (string) Fleet::resolved( $site, $key )['value'] : '';
	}
	/** Hours and List variables for the block inspectors. */
	public function editor_data(): void {
		$data = array(
			'hours' => array(),
			'list'  => array(),
		);
		foreach ( Fleet::definitions() as $key => $d ) {
			if ( isset( $data[ $d['type'] ] ) ) {
				$data[ $d['type'] ][] = array(
					'key'   => $key,
					'label' => $d['label'],
				);
			}
		}
		foreach ( array( 'hours', 'list' ) as $block ) {
			wp_add_inline_script(
				"brand-fleet-{$block}-editor",
				'window.brandFleetBlocks = ' . wp_json_encode( $data ) . ';',
				'before'
			);
		}
	}

	/** Adds the Brand Fleet block category to the inserter. */
	public function category( array $categories ): array {
		$categories[] = array(
			'slug'  => 'brand-fleet',
			'title' => __( 'Brand Fleet', 'brand-fleet' ),
			'icon'  => 'networking',
		);
		return $categories;
	}

	/** Registers the plugin's blocks and their editor scripts. */
	public function register(): void {
		$editor_deps = array(
			'wp-blocks',
			'wp-element',
			'wp-block-editor',
			'wp-components',
			'wp-server-side-render',
			'wp-i18n',
		);

		foreach ( self::BLOCKS as $block ) {
			$dir = BRAND_FLEET_DIR . 'blocks/' . $block;

			wp_register_script(
				"brand-fleet-{$block}-editor",
				BRAND_FLEET_URL . "blocks/{$block}/editor.js",
				$editor_deps,
				BRAND_FLEET_VERSION,
				true
			);

			if ( file_exists( $dir . '/view.js' ) ) {
				wp_register_script(
					"brand-fleet-{$block}-view",
					BRAND_FLEET_URL . "blocks/{$block}/view.js",
					array(),
					BRAND_FLEET_VERSION,
					array(
						'in_footer' => true,
						'strategy'  => 'defer',
					)
				);
			}

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
