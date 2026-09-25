<?php
/**
 * Cross-site content pull + per-site token swap.
 *
 * The master brand site is the single source of truth for shared pages.
 * Spoke sites render those pages live through the
 * brand-fleet/shared-content block: the RAW block markup is fetched from the
 * master (switch_to_blog), then rendered and token-swapped in the CURRENT
 * site's context so {{business_name}}, {{phone_sales}}, … resolve to the
 * spoke's own settings.
 *
 * Cache posture: the raw pulled markup is object-cached for 5 minutes per
 * (master, slug); rendered output varies only by site, never by visitor.
 */

namespace BrandFleet;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Content {

	const CACHE_GROUP = 'brand-fleet';
	const CACHE_TTL   = 300;

	/** Recursion guard for nested shared-content renders. */
	private static int $depth = 0;

	public function init(): void {
		// Swap tokens in any locally rendered content too, so pages authored
		// on the master site (or one-off local pages) can use the same
		// {{tokens}} and resolve to the current site's identity.
		add_filter( 'the_content', array( $this, 'swap_tokens' ), 12 );
	}

	/** Replace {{token}} placeholders with the current site's brand values. */
	public function swap_tokens( string $html ): string {
		$tokens = Settings::tokens();
		// Only replace text, never attributes, scripts or markup. Values are plain text.
		$parts = wp_html_split( $html );
		foreach ( $parts as $i => $part ) {
			if ( 0 === $i % 2 ) { $parts[ $i ] = strtr( $part, array_map( 'esc_html', $tokens ) ); }
		}
		return implode( '', $parts );
	}

	/**
	 * Fetch the RAW content of a master-site page.
	 *
	 * @param string $slug Page path on the master site; '' means its front page.
	 * @return string Raw block markup, or '' when unresolvable.
	 */
	public function pull_raw( string $slug ): string {
		$master = Settings::master_blog_id();
		if ( 0 === $master || get_current_blog_id() === $master ) {
			return '';
		}

		$cache_key = 'pull_' . $master . '_' . md5( $slug );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_string( $cached ) ) {
			return $cached;
		}

		$raw = '';
		switch_to_blog( $master );
		$post = null;
		if ( '' === $slug ) {
			$front_id = (int) get_option( 'page_on_front' );
			$post     = $front_id ? get_post( $front_id ) : null;
		} else {
			$post = get_page_by_path( $slug, OBJECT, array( 'page' ) );
		}
		if ( $post instanceof \WP_Post && 'publish' === $post->post_status ) {
			$raw = (string) $post->post_content;
		}
		restore_current_blog();

		wp_cache_set( $cache_key, $raw, self::CACHE_GROUP, self::CACHE_TTL );
		return $raw;
	}

	/**
	 * Render a master-site page in the CURRENT site's context.
	 *
	 * Rendering happens after restore_current_blog() on purpose: blocks and
	 * the token filter must resolve against the spoke site's options, not the
	 * master's.
	 */
	public function render_shared( string $slug ): string {
		if ( self::$depth >= 2 ) {
			return '';
		}
		$raw = $this->pull_raw( $slug );
		if ( '' === $raw ) {
			return '';
		}
		++self::$depth;
		$html = apply_filters( 'the_content', $raw );
		--self::$depth;
		return $html;
	}
}
