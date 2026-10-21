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
 * Cache posture: the raw pulled markup is object-cached (global group) for 5 minutes
 * per (master, kind, slug) and deleted when the master edits it; rendered output varies
 * only by site, never by visitor.
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

	/** Attributes that hold a link: tokens there must resolve to a safe URL as a whole. */
	const URL_ATTRIBUTES = array( 'href', 'src', 'action', 'formaction', 'poster', 'cite' );
	/** Attributes that show text to people; any variable type may appear here. */
	const TEXT_ATTRIBUTES = array( 'alt', 'title', 'aria-label', 'aria-description', 'placeholder', 'value', 'content', 'label' );

	public function init(): void {
		// Swap tokens in any locally rendered content too, so pages authored
		// on the master site (or one-off local pages) can use the same
		// {{tokens}} and resolve to the current site's identity.
		add_filter( 'the_content', array( $this, 'swap_tokens' ), 12 );
		// Block theme headers and footers render outside the_content.
		add_filter( 'render_block_core/template-part', array( $this, 'swap_tokens' ), 12 );
		add_filter( 'widget_block_content', array( $this, 'swap_tokens' ), 12 );
		// Titles, excerpts and SEO metadata are plain text.
		$text = array( 'the_title', 'single_post_title', 'get_the_excerpt', 'wpseo_title', 'wpseo_metadesc', 'wpseo_opengraph_title', 'wpseo_opengraph_desc', 'wpseo_twitter_title', 'wpseo_twitter_description', 'rank_math/frontend/title', 'rank_math/frontend/description', 'rank_math/opengraph/facebook/og_title', 'rank_math/opengraph/facebook/og_description', 'rank_math/opengraph/twitter/twitter_title', 'rank_math/opengraph/twitter/twitter_description' );
		/** Filters plain-text hooks whose output swaps {{tokens}}. */
		foreach ( (array) apply_filters( 'brand_fleet_text_filters', $text ) as $hook ) { add_filter( $hook, array( $this, 'swap_text' ), 20 ); }
		add_filter( 'document_title_parts', array( $this, 'swap_title_parts' ), 20 );
		add_filter( 'get_post_metadata', array( $this, 'swap_meta' ), 20, 4 );
		add_action( 'init', array( $this, 'register_binding' ) );
		wp_cache_add_global_groups( array( self::CACHE_GROUP ) );
		add_action( 'transition_post_status', array( $this, 'post_status_changed' ), 10, 3 );
		add_action( 'post_updated', array( $this, 'post_updated' ), 10, 3 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'editor_binding' ) );
	}

	/** Raw values and types, keyed by token. */
	private static function token_map(): array {
		$defs = Fleet::definitions(); $map = array();
		foreach ( Settings::tokens() as $token => $value ) { $map[ $token ] = array( (string) $value, $defs[ substr( $token, 2, -2 ) ]['type'] ?? 'text' ); }
		return $map;
	}
	/** Front-end output only; wp-admin keeps showing the {{tokens}} people typed. */
	private static function front(): bool { return ! is_admin(); }

	/** Replace {{token}} placeholders with the current site's brand values. */
	public function swap_tokens( $html ) {
		if ( ! is_string( $html ) || ! str_contains( $html, '{{' ) ) { return $html; }
		$map = self::token_map();
		$tokens = array_map( static fn( $entry ) => esc_html( $entry[0] ), $map );
		// Text first: values are plain text. Scripts and markup are never touched.
		$parts = wp_html_split( $html );
		foreach ( $parts as $i => $part ) {
			if ( 0 === $i % 2 ) { $parts[ $i ] = strtr( $part, $tokens ); }
		}
		$html = implode( '', $parts );
		return str_contains( $html, '{{' ) ? self::swap_attributes( $html, $map ) : $html;
	}

	/**
	 * Attribute values, by type: links and images resolve to escaped URLs, colors and
	 * numbers are pre-validated, and text only lands in attributes people read. Event
	 * handlers never change, and link attributes are re-validated as a whole URL.
	 */
	private static function swap_attributes( string $html, array $map ): string {
		$tags = new \WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag() ) {
			foreach ( (array) $tags->get_attribute_names_with_prefix( '' ) as $name ) {
				$value = $tags->get_attribute( $name );
				if ( ! is_string( $value ) || ! str_contains( $value, '{{' ) || str_starts_with( $name, 'on' ) ) { continue; }
				$is_url = in_array( $name, self::URL_ATTRIBUTES, true );
				$swapped = preg_replace_callback(
					'/\{\{([a-z][a-z0-9_]{0,47})\}\}/',
					static function ( $m ) use ( $map, $name, $is_url ) {
						if ( ! isset( $map[ $m[0] ] ) ) { return $m[0]; }
						list( $raw, $type ) = $map[ $m[0] ];
						if ( in_array( $type, array( 'color', 'number' ), true ) ) { return $raw; }
						if ( in_array( $type, array( 'url', 'image' ), true ) ) { return $is_url ? $raw : strtr( esc_url_raw( $raw ), array( '(' => '%28', ')' => '%29', "'" => '%27', ';' => '%3B' ) ); }
						if ( $is_url && in_array( $type, array( 'email', 'phone' ), true ) ) { return $raw; }
						return in_array( $name, self::TEXT_ATTRIBUTES, true ) ? $raw : $m[0];
					},
					$value
				);
				if ( $swapped === $value ) { continue; }
				$tags->set_attribute( $name, $is_url ? esc_url_raw( $swapped ) : $swapped );
			}
		}
		return $tags->get_updated_html();
	}

	/** Plain-text hooks: titles, excerpts and SEO fields. */
	public function swap_text( $text ) {
		if ( ! is_string( $text ) || ! str_contains( $text, '{{' ) || ! self::front() ) { return $text; }
		return strtr( $text, array_map( static fn( $entry ) => esc_html( $entry[0] ), self::token_map() ) );
	}
	public function swap_title_parts( $parts ) {
		return is_array( $parts ) ? array_map( array( $this, 'swap_text' ), $parts ) : $parts;
	}

	/** Custom fields chosen in Variable definitions return each site's values on the front end. */
	public function swap_meta( $value, $object_id, $meta_key, $single ) {
		static $keys = null;
		if ( null !== $value || ! $meta_key || ! self::front() ) { return $value; }
		$keys = $keys ?? Fleet::meta_keys();
		if ( ! in_array( $meta_key, $keys, true ) ) { return $value; }
		remove_filter( 'get_post_metadata', array( $this, 'swap_meta' ), 20 );
		$raw = get_post_meta( (int) $object_id, $meta_key, false );
		add_filter( 'get_post_metadata', array( $this, 'swap_meta' ), 20, 4 );
		if ( ! $raw ) { return $value; }
		$values = array_map( static fn( $entry ) => $entry[0], self::token_map() );
		return array_map( static fn( $item ) => is_string( $item ) ? strtr( $item, $values ) : $item, (array) $raw );
	}

	/** Block bindings: connect a heading, paragraph, image or button to a variable in the editor. */
	public function register_binding(): void {
		if ( ! function_exists( 'register_block_bindings_source' ) ) { return; }
		register_block_bindings_source(
			'brand-fleet/variable',
			array(
				'label'              => 'Brand Fleet variable',
				'get_value_callback' => static function ( array $args ) {
					$entry = self::token_map()[ '{{' . sanitize_key( $args['key'] ?? '' ) . '}}' ] ?? null;
					if ( ! $entry ) { return null; }
					return in_array( $entry[1], array( 'url', 'image' ), true ) ? esc_url( $entry[0] ) : esc_html( $entry[0] );
				},
			)
		);
	}
	public function editor_binding(): void {
		if ( ! current_user_can( 'edit_posts' ) ) { return; }
		$fields = array();
		foreach ( self::token_map() as $token => $entry ) {
			$key = substr( $token, 2, -2 ); $d = Fleet::definitions()[ $key ] ?? null;
			$fields[] = array( 'key' => $key, 'label' => $d['label'] ?? ucwords( str_replace( '_', ' ', $key ) ), 'value' => $entry[0] );
		}
		wp_enqueue_script( 'brand-fleet-bindings', BRAND_FLEET_URL . 'assets/fleet-bindings.js', array( 'wp-blocks' ), BRAND_FLEET_VERSION, true );
		wp_localize_script( 'brand-fleet-bindings', 'brandFleetBindings', array( 'fields' => $fields ) );
	}

	/** Shared content kinds: a master page, or a master synced pattern (a "showcase": FAQ set, team list) with no URL of its own. */
	const KINDS = array( 'page' => 'page', 'pattern' => 'wp_block' );

	/**
	 * Fetch the RAW content of a master-site page or synced pattern.
	 *
	 * The cache group is global, so one copy serves every location and an edit on the
	 * master can delete it (see master_changed()).
	 *
	 * @param string $slug Page path or pattern slug on the master site; '' means its front page.
	 * @return string Raw block markup, or '' when unresolvable.
	 */
	public function pull_raw( string $slug, string $kind = 'page' ): string {
		$master = Settings::master_blog_id();
		if ( 0 === $master || get_current_blog_id() === $master || ! isset( self::KINDS[ $kind ] ) ) {
			return '';
		}

		$cache_key = self::cache_key( $master, $kind, $slug );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_string( $cached ) ) {
			return $cached;
		}

		$raw = '';
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- Read one post from the master; restored right after.
		switch_to_blog( $master );
		$post = null;
		if ( '' === $slug && 'page' === $kind ) {
			$front_id = (int) get_option( 'page_on_front' );
			$post     = $front_id ? get_post( $front_id ) : null;
		} elseif ( '' !== $slug ) {
			$post = get_page_by_path( $slug, OBJECT, array( self::KINDS[ $kind ] ) );
		}
		if ( $post instanceof \WP_Post && 'publish' === $post->post_status ) {
			$raw = (string) $post->post_content;
		}
		restore_current_blog();

		wp_cache_set( $cache_key, $raw, self::CACHE_GROUP, self::CACHE_TTL );
		return $raw;
	}

	private static function cache_key( int $master, string $kind, string $slug ): string {
		return 'pull_' . $master . '_' . $kind . '_' . md5( $slug );
	}

	/**
	 * Slugs to try, most specific first. "privacy-{{province}}" tries "privacy-on" (this
	 * site's province), then "privacy"; "{{language}}/privacy" tries "fr/privacy", then "privacy".
	 */
	public static function candidates( string $slug ): array {
		if ( ! str_contains( $slug, '{{' ) ) { return array( $slug ); }
		$values = array_map( static fn( $value ) => sanitize_title( (string) $value ), Settings::tokens() );
		$specific = strtr( $slug, $values );
		$generic = trim( (string) preg_replace( array( '/\{\{[a-z][a-z0-9_]{0,47}\}\}/', '#[-_]*/[-_/]*#', '/[-_]{2,}/' ), array( '', '/', '-' ), $slug ), "-_/ \t" );
		$out = array();
		// A token with no value (or an unknown token) leaves {{ behind or an empty piece: skip that candidate.
		if ( ! str_contains( $specific, '{{' ) && ! preg_match( '#(^|/)[-_]*($|/)|[-_]$|^[-_]|[-_]{2,}#', $specific ) ) { $out[] = trim( $specific, '/' ); }
		$out[] = $generic;
		return array_values( array_unique( array_filter( $out, 'strlen' ) ) );
	}

	/**
	 * Render a master-site page or pattern in the CURRENT site's context.
	 *
	 * Rendering happens after restore_current_blog() on purpose: blocks and
	 * the token filter must resolve against the spoke site's options, not the
	 * master's.
	 */
	public function render_shared( string $slug, string $kind = 'page' ): string {
		if ( self::$depth >= 2 ) {
			return '';
		}
		$raw = '';
		foreach ( self::candidates( $slug ) as $candidate ) {
			$raw = $this->pull_raw( $candidate, $kind );
			if ( '' !== $raw ) { break; }
		}
		if ( '' === $raw && '' === $slug ) { $raw = $this->pull_raw( '', $kind ); }
		if ( '' === $raw ) {
			return '';
		}
		++self::$depth;
		$html = apply_filters( 'the_content', $raw );
		--self::$depth;
		return $html;
	}

	/** A master page or pattern changed: drop the shared copy and purge the locations that show it. */
	public function post_status_changed( string $new, string $old, \WP_Post $post ): void {
		if ( 'publish' === $new || 'publish' === $old ) { $this->master_changed( $post ); }
	}
	public function post_updated( int $id, \WP_Post $after, \WP_Post $before ): void {
		// A renamed or moved page leaves its old path cached; the status hook covers the new one.
		if ( $before->post_name !== $after->post_name || $before->post_parent !== $after->post_parent ) { $this->master_changed( $before, false ); }
	}
	private function master_changed( \WP_Post $post, bool $purge = true ): void {
		$kind = array_search( $post->post_type, self::KINDS, true );
		if ( false === $kind ) { return; }
		$master = get_current_blog_id();
		$slug = 'page' === $kind ? get_page_uri( $post ) : $post->post_name;
		wp_cache_delete( self::cache_key( $master, (string) $kind, (string) $slug ), self::CACHE_GROUP );
		if ( 'page' === $kind && (int) get_option( 'page_on_front' ) === $post->ID ) { wp_cache_delete( self::cache_key( $master, 'page', '' ), self::CACHE_GROUP ); }
		if ( $purge ) { Fleet_Cache::consumers_of( $master ); }
	}
}
