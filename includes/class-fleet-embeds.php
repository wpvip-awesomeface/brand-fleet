<?php
/**
 * Network-locked embed code (analytics, chat widgets, tag managers) printed on enrolled sites.
 *
 * Only network admins who may post unfiltered HTML can save an embed, and sites can never
 * edit one. Per-site values reach an embed through {{tokens}}, and only when the value is a
 * plain ID or URL, so a site value can never break out of the network's markup.
 */
namespace BrandFleet;

defined( 'ABSPATH' ) || exit;
class Fleet_Embeds {
	/** Characters a token value may use inside embed code: IDs, keys and URLs, never quotes, brackets or semicolons. */
	const SAFE_VALUE = '/^[A-Za-z0-9 _.,:@\/#+?=&%~\-]*$/D';

	public function init(): void {
		add_action( 'wp_head', static fn() => self::output( 'head' ), 20 );
		add_action( 'wp_body_open', static fn() => self::output( 'body' ), 5 );
		add_action( 'wp_footer', static fn() => self::output( 'footer' ), 20 );
	}

	/** Embeds for the current site at one placement, with tokens resolved. */
	public static function markup( string $placement ): string {
		$site = get_current_blog_id();
		if ( ! Fleet::profile( $site ) ) { return ''; }
		$tokens = null; $out = '';
		foreach ( Fleet::definitions() as $key => $d ) {
			if ( 'embed' !== $d['type'] || '' === trim( (string) $d['default'] ) || ( $d['placement'] ?? 'head' ) !== $placement ) { continue; }
			if ( ! empty( $d['targets'] ) && ! in_array( $site, $d['targets'], true ) ) { continue; }
			if ( ! empty( $d['segment'] ) && ! Fleet_Segments::matches( $site, $d['segment'] ) ) { continue; }
			$tokens = $tokens ?? array_map( static fn( $value ) => preg_match( self::SAFE_VALUE, (string) $value ) ? (string) $value : '', Settings::tokens() );
			$out .= "\n<!-- Brand Fleet embed: " . esc_html( $key ) . " -->\n" . strtr( (string) $d['default'], $tokens ) . "\n";
		}
		return $out;
	}

	private static function output( string $placement ): void {
		if ( is_admin() || is_feed() || is_embed() ) { return; }
		echo self::markup( $placement ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Network-locked markup saved by a network admin with unfiltered_html; token values are restricted to SAFE_VALUE.
	}
}
