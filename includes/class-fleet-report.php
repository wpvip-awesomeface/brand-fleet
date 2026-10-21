<?php
/**
 * Token check: finds {{tokens}} that would print as raw text, because no variable has that
 * key, or that print nothing, because the site's value is empty. Read-only; runs as a
 * background batch so it scales to the whole fleet. Useful after converting legacy
 * shortcodes or SmartCodes to tokens.
 */
namespace BrandFleet;

defined( 'ABSPATH' ) || exit;
class Fleet_Report {
	const TYPES = array(
		'page'             => 'Pages',
		'post'             => 'Posts',
		'wp_block'         => 'Synced patterns',
		'wp_template_part' => 'Headers, footers and template parts',
		'wp_template'      => 'Templates',
		'wp_navigation'    => 'Menus',
	);
	/** Posts listed per site; the count of further posts is still reported. */
	const MAX_POSTS = 20;
	const TOKEN     = '/\{\{\s*([A-Za-z][A-Za-z0-9_]{0,47})\s*\}\}/';

	/** Normalizes the token report options. */
	public static function spec( array $in ): array {
		$types = array_values( array_intersect( array_keys( self::TYPES ), (array) ( $in['types'] ?? array() ) ) );
		return array( 'types' => $types ? $types : array_keys( self::TYPES ) );
	}

	/** One site's result row. Unknown tokens print as raw {{text}}; empty ones print nothing. */
	public static function row( int $site, array $spec ): array {
		if ( ! Fleet::site( $site ) ) {
			return array(
				'site'   => $site,
				'status' => 'skipped',
				'error'  => 'Site is no longer active.',
			);
		}
		global $wpdb;
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- Read-only scan of one site's posts; always restored.
		switch_to_blog( $site );
		try {
			$map     = Settings::tokens();
			$defs    = Fleet::definitions();
			$matches = array();
			$more    = 0;
			$types   = implode( ',', array_fill( 0, count( $spec['types'] ), '%s' ) );
			$like    = '%' . $wpdb->esc_like( '{{' ) . '%';
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Background scan of one site for "{{"; placeholders are generated to match the array.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($types) AND post_status IN ('publish','draft','pending','future','private') AND ( post_title LIKE %s OR post_content LIKE %s OR post_excerpt LIKE %s ) ORDER BY ID ASC LIMIT 500",
					...array_merge( $spec['types'], array( $like, $like, $like ) )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			foreach ( array_map( 'intval', (array) $ids ) as $id ) {
				$post = get_post( $id );
				if ( ! $post ) {
					continue;
				}
				$unknown = array();
				$empty   = array();
				preg_match_all(
					self::TOKEN,
					$post->post_title . "\n" . $post->post_content . "\n" . $post->post_excerpt,
					$found
				);
				foreach ( array_unique( $found[1] ) as $key ) {
					if ( ! isset( $map[ '{{' . $key . '}}' ] ) ) {
						// Embed keys resolve only inside embeds, never in content.
						$unknown[] = isset( $defs[ $key ] ) && 'embed' === $defs[ $key ]['type'] ? $key . ' (embed)' : $key;
					} elseif ( '' === trim( (string) $map[ '{{' . $key . '}}' ] ) ) {
						$empty[] = $key;
					}
				}
				if ( ! $unknown && ! $empty ) {
					continue;
				}
				if ( count( $matches ) >= self::MAX_POSTS ) {
					++$more;
					continue;
				}
				$matches[] = array(
					'post'    => $id,
					'type'    => $post->post_type,
					'status'  => $post->post_status,
					'title'   => mb_substr( wp_strip_all_tags( $post->post_title ), 0, 80 ),
					'unknown' => $unknown,
					'empty'   => $empty,
				);
			}
			return array(
				'site'     => $site,
				'status'   => $matches ? 'issues' : 'clean',
				'matches'  => $matches,
				'more'     => $more,
				'enrolled' => (bool) Fleet::profile( $site ),
			);
		} catch ( \Throwable $e ) {
			return array(
				'site'   => $site,
				'status' => 'skipped',
				'error'  => $e->getMessage(),
			);
		} finally {
			restore_current_blog();
		}
	}
}
