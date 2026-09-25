<?php
/**
 * Per-site brand settings: option keys, defaults, accessors, token map, and
 * Settings API registration for the "Brand Identity" settings page.
 *
 * Every option is a plain per-site option (get_option), so each brand site's
 * identity is isolated exactly like its content.
 */

namespace BrandFleet;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {

	const PREFIX = 'brand_fleet_';

	const GROUP = 'brand_fleet_settings';
	const PAGE  = 'brand-fleet-settings';

	const OPT_BUSINESS_NAME   = 'brand_fleet_business_name';
	const OPT_LEGAL_NAME      = 'brand_fleet_legal_name';
	const OPT_PARENT_LOGO_URL = 'brand_fleet_parent_logo_url';
	const OPT_LOGO_URL        = 'brand_fleet_logo_url';
	const OPT_LOGO_FOOTER_URL = 'brand_fleet_logo_footer_url';
	const OPT_ACCENT          = 'brand_fleet_accent';
	const OPT_PHONE_SALES     = 'brand_fleet_phone_sales';
	const OPT_PHONE_SUPPORT   = 'brand_fleet_phone_support';
	const OPT_ADDRESS         = 'brand_fleet_address';
	const OPT_MASTER_SITE     = 'brand_fleet_master_site';

	/** Social profile URLs; an empty value means "do not render this network". */
	const SOCIAL_OPTS = array(
		'brand_fleet_social_facebook'  => 'Facebook',
		'brand_fleet_social_linkedin'  => 'LinkedIn',
		'brand_fleet_social_instagram' => 'Instagram',
		'brand_fleet_social_youtube'   => 'YouTube',
		'brand_fleet_social_x'         => 'X',
	);

	const DEFAULT_ACCENT = '#3858e9';

	public static function defaults(): array {
		$defaults = array(
			self::OPT_BUSINESS_NAME   => '',
			self::OPT_LEGAL_NAME      => '',
			self::OPT_PARENT_LOGO_URL => '',
			self::OPT_LOGO_URL        => '',
			self::OPT_LOGO_FOOTER_URL => '',
			self::OPT_ACCENT          => self::DEFAULT_ACCENT,
			self::OPT_PHONE_SALES     => '',
			self::OPT_PHONE_SUPPORT   => '',
			self::OPT_ADDRESS         => '',
			self::OPT_MASTER_SITE     => '',
		);
		foreach ( array_keys( self::SOCIAL_OPTS ) as $key ) {
			$defaults[ $key ] = '';
		}
		return $defaults;
	}

	/** Option keys the MCP update-site-option ability may write. */
	public static function mcp_writable_keys(): array {
		return array_keys( self::defaults() );
	}

	public static function get( string $key ): string {
		if ( Fleet::profile( get_current_blog_id() ) ) {
			if ( self::OPT_MASTER_SITE === $key ) { return (string) ( Fleet::profile( get_current_blog_id() )['source_id'] ?? 0 ); }
			return Fleet::resolved( get_current_blog_id(), substr( $key, strlen( self::PREFIX ) ) )['value'];
		}
		$defaults = self::defaults();
		$value    = get_option( $key, $defaults[ $key ] ?? '' );
		return is_string( $value ) ? $value : (string) $value;
	}

	/** Display name for the current brand; falls back to the site title. */
	public static function business_name(): string {
		$name = trim( self::get( self::OPT_BUSINESS_NAME ) );
		if ( Fleet::profile( get_current_blog_id() ) ) { return $name; }
		return '' !== $name ? $name : get_bloginfo( 'name' );
	}

	/** Legal entity name; falls back to the business name. */
	public static function legal_name(): string {
		$name = trim( self::get( self::OPT_LEGAL_NAME ) );
		if ( Fleet::profile( get_current_blog_id() ) ) { return $name; }
		return '' !== $name ? $name : self::business_name();
	}

	public static function accent(): string {
		$accent = sanitize_hex_color( self::get( self::OPT_ACCENT ) );
		return $accent ? $accent : self::DEFAULT_ACCENT;
	}

	/**
	 * The constant parent-company mark shown beside the swapping brand logo.
	 * Falls back to the master site's parent logo so spoke sites inherit it
	 * without re-entering the URL.
	 */
	public static function parent_logo_url(): string {
		$url = trim( self::get( self::OPT_PARENT_LOGO_URL ) );
		if ( Fleet::profile( get_current_blog_id() ) ) { return $url; }
		if ( '' !== $url ) {
			return $url;
		}
		$master = self::master_blog_id();
		if ( 0 === $master || get_current_blog_id() === $master ) {
			return '';
		}
		switch_to_blog( $master );
		$url = trim( (string) get_option( self::OPT_PARENT_LOGO_URL, '' ) );
		restore_current_blog();
		return $url;
	}

	/** Footer logo (dark-background variant); falls back to the brand logo. */
	public static function footer_logo_url(): string {
		$url = trim( self::get( self::OPT_LOGO_FOOTER_URL ) );
		return '' !== $url ? $url : trim( self::get( self::OPT_LOGO_URL ) );
	}

	/** Non-empty social links, as array( label => url ). */
	public static function socials(): array {
		$links = array();
		foreach ( self::SOCIAL_OPTS as $key => $label ) {
			$url = trim( self::get( $key ) );
			if ( '' !== $url ) {
				$links[ $label ] = $url;
			}
		}
		return $links;
	}

	/**
	 * Resolve the master (content source) site to a blog ID.
	 *
	 * The option accepts a numeric blog ID or a sub-site path (e.g.
	 * "master-brand"). Returns 0 when unset or unresolvable — meaning this site
	 * has no master (it IS the master, or is standalone).
	 */
	public static function master_blog_id(): int {
		$raw = trim( self::get( self::OPT_MASTER_SITE ) );
		if ( '' === $raw ) {
			return 0;
		}
		if ( is_numeric( $raw ) ) {
			return (int) $raw;
		}
		$path  = '/' . trim( $raw, '/' ) . '/';
		$sites = get_sites(
			array(
				'path'   => $path,
				'number' => 1,
				'fields' => 'ids',
			)
		);
		return $sites ? (int) $sites[0] : 0;
	}

	/**
	 * The token map applied to rendered content on this site.
	 *
	 * Deterministic per site (options only) — no visitor state — so swapped
	 * output stays fully edge-cacheable.
	 */
	public static function tokens(): array {
		$address = trim( self::get( self::OPT_ADDRESS ) );
		$address = preg_replace( '/\s*\R\s*/', ', ', $address );
		$tokens = array(
			'{{business_name}}' => self::business_name(),
			'{{legal_name}}'    => self::legal_name(),
			'{{phone_sales}}'   => trim( self::get( self::OPT_PHONE_SALES ) ),
			'{{phone_support}}' => trim( self::get( self::OPT_PHONE_SUPPORT ) ),
			'{{address}}'       => $address,
		);
		if ( Fleet::profile( get_current_blog_id() ) ) {
			foreach ( Fleet::definitions() as $key => $definition ) {
				$token = '{{' . $key . '}}';
				if ( ! isset( $tokens[ $token ] ) ) { $tokens[ $token ] = Fleet::resolved( get_current_blog_id(), $key )['value']; }
			}
		}
		return $tokens;
	}

	public function init(): void {
		add_action( 'admin_init', array( $this, 'register' ) );
	}

	public function register(): void {
		$text = array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		);
		$url  = array(
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
		);
		register_setting( self::GROUP, self::OPT_BUSINESS_NAME, $text );
		register_setting( self::GROUP, self::OPT_LEGAL_NAME, $text );
		register_setting( self::GROUP, self::OPT_PARENT_LOGO_URL, $url );
		register_setting( self::GROUP, self::OPT_LOGO_URL, $url );
		register_setting( self::GROUP, self::OPT_LOGO_FOOTER_URL, $url );
		register_setting(
			self::GROUP,
			self::OPT_ACCENT,
			array(
				'type'              => 'string',
				'sanitize_callback' => static fn( $v ) => sanitize_hex_color( (string) $v ) ?: self::DEFAULT_ACCENT,
			)
		);
		register_setting( self::GROUP, self::OPT_PHONE_SALES, $text );
		register_setting( self::GROUP, self::OPT_PHONE_SUPPORT, $text );
		register_setting(
			self::GROUP,
			self::OPT_ADDRESS,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			)
		);
		register_setting( self::GROUP, self::OPT_MASTER_SITE, $text );
		foreach ( array_keys( self::SOCIAL_OPTS ) as $key ) {
			register_setting( self::GROUP, $key, $url );
		}
	}
}
