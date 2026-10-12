<?php
/**
 * Fleet-wide find and replace for page content that is not a variable.
 *
 * Runs inside Fleet_Jobs as a "replace" batch, so it gets the same preview-then-apply,
 * background and audit behavior as bulk updates. A preview records each matching post
 * with a hash of its text; apply skips any post edited since, and every change goes
 * through wp_update_post(), so WordPress keeps a revision to roll back to.
 */
namespace BrandFleet;

defined( 'ABSPATH' ) || exit;
class Fleet_Replace {
	const TYPES = array( 'page' => 'Pages', 'post' => 'Posts', 'wp_block' => 'Synced patterns', 'wp_template_part' => 'Headers, footers and template parts', 'wp_template' => 'Templates' );
	const DEFAULT_TYPES = array( 'page', 'post', 'wp_block', 'wp_template_part' );
	/** Matching posts kept per site; more are reported, not stored, so batch rows stay small. */
	const MAX_MATCHES = 20;
	const STATUSES = array( 'publish', 'draft', 'pending', 'future', 'private' );

	/** Validate a request once, before any site is touched. */
	public static function spec( array $in ): array {
		$find = (string) ( $in['find'] ?? '' );
		$with = (string) ( $in['with'] ?? '' );
		if ( ! empty( $in['variable'] ) ) {
			$key = sanitize_key( (string) $in['variable'] );
			$d = Fleet::definitions()[ $key ] ?? null;
			if ( ! $d || 'embed' === $d['type'] ) { throw new \InvalidArgumentException( 'Choose a variable to insert.' ); }
			$with = '{{' . $key . '}}';
		}
		if ( mb_strlen( trim( $find ) ) < 2 || mb_strlen( $find ) > 200 ) { throw new \InvalidArgumentException( 'Enter 2 to 200 characters to find.' ); }
		if ( mb_strlen( $with ) > 500 ) { throw new \InvalidArgumentException( 'Replacement text can be up to 500 characters.' ); }
		if ( $with === $find ) { throw new \InvalidArgumentException( 'The replacement is the same as the text you are looking for.' ); }
		$markup = ! empty( $in['markup'] );
		if ( $markup && preg_match( '/["<>\\\\]/', $with ) ) { throw new \InvalidArgumentException( 'When links and block settings are included, the replacement cannot contain quotes, angle brackets or backslashes.' ); }
		$types = array_values( array_intersect( array_keys( self::TYPES ), (array) ( $in['types'] ?? self::DEFAULT_TYPES ) ) );
		if ( ! $types ) { throw new \InvalidArgumentException( 'Choose at least one kind of content to search.' ); }
		return array( 'find' => $find, 'with' => $with, 'case' => ! empty( $in['case'] ), 'markup' => $markup, 'types' => $types );
	}

	private static function pattern( array $spec ): string {
		return '/' . preg_quote( $spec['find'], '/' ) . '/u' . ( $spec['case'] ? '' : 'i' );
	}

	/** Replace in visible text; with markup on, also inside tags, links and block settings. */
	public static function swap( string $html, array $spec, ?int &$count ): string {
		$pattern = self::pattern( $spec ); $text = esc_html( $spec['with'] ); $count = 0;
		if ( $spec['markup'] ) {
			$parts = wp_html_split( $html );
			foreach ( $parts as $i => $part ) { $parts[ $i ] = self::literal( $pattern, 0 === $i % 2 ? $text : $spec['with'], $part, $n ); $count += $n; }
			return implode( '', $parts );
		}
		$parts = wp_html_split( $html );
		foreach ( $parts as $i => $part ) {
			if ( 0 !== $i % 2 ) { continue; }
			$parts[ $i ] = self::literal( $pattern, $text, $part, $n ); $count += $n;
		}
		return implode( '', $parts );
	}

	/** preg_replace without $1 or \\1 expansion: the replacement is always literal text. */
	private static function literal( string $pattern, string $with, string $subject, ?int &$count ): string {
		return (string) preg_replace_callback( $pattern, static fn() => $with, $subject, -1, $count );
	}

	private static function fingerprint( \WP_Post $post ): string {
		return hash( 'sha256', $post->post_title . "\0" . $post->post_content . "\0" . $post->post_excerpt );
	}

	/** Matches on the current (switched-to) site. */
	public static function find( array $spec ): array {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $spec['find'] ) . '%';
		$types = implode( ',', array_fill( 0, count( $spec['types'] ), '%s' ) );
		$statuses = implode( ',', array_fill( 0, count( self::STATUSES ), '%s' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Background network-admin scan of one site's posts for an exact phrase; WP_Query search splits words and may route to Elasticsearch. Placeholders are generated to match the arrays.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($types) AND post_status IN ($statuses) AND ( post_title LIKE %s OR post_content LIKE %s OR post_excerpt LIKE %s ) ORDER BY ID ASC LIMIT %d", ...array_merge( $spec['types'], self::STATUSES, array( $like, $like, $like, self::MAX_MATCHES * 3 ) ) ) );
		// phpcs:enable
		$matches = array(); $more = false;
		foreach ( array_map( 'intval', (array) $ids ) as $id ) {
			$post = get_post( $id );
			if ( ! $post ) { continue; }
			$count = 0;
			foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $field ) { self::swap( (string) $post->$field, $spec, $n ); $count += $n; }
			if ( ! $count ) { continue; }
			if ( count( $matches ) >= self::MAX_MATCHES ) { $more = true; break; }
			$matches[] = array( 'post' => $id, 'type' => $post->post_type, 'status' => $post->post_status, 'title' => mb_substr( wp_strip_all_tags( $post->post_title ), 0, 80 ), 'count' => $count, 'snippet' => self::snippet( $post, $spec ), 'hash' => self::fingerprint( $post ) );
		}
		return array( 'matches' => $matches, 'more' => $more || count( (array) $ids ) >= self::MAX_MATCHES * 3 );
	}

	/** Words around the first visible match, as plain text pieces for highlighting. */
	private static function snippet( \WP_Post $post, array $spec ): array {
		foreach ( array( $post->post_title, $post->post_content, $post->post_excerpt ) as $field ) {
			$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) preg_replace( '/<!--.*?-->/s', ' ', (string) $field ) ) ) );
			if ( ! preg_match( self::pattern( $spec ), $text, $m, PREG_OFFSET_CAPTURE ) ) { continue; }
			$at = mb_strlen( substr( $text, 0, $m[0][1] ) ); $len = mb_strlen( $m[0][0] );
			$start = max( 0, $at - 60 );
			return array( ( $start ? '…' : '' ) . mb_substr( $text, $start, $at - $start ), $m[0][0], mb_substr( $text, $at + $len, 60 ) . ( mb_strlen( $text ) > $at + $len + 60 ? '…' : '' ) );
		}
		return array( '', $spec['find'], ' (inside links or block settings)' );
	}

	/** Apply to one post on the current site, unless it changed since preview. */
	public static function replace_post( int $id, array $spec, string $hash ): string {
		$post = get_post( $id );
		if ( ! $post ) { return 'deleted'; }
		if ( ! hash_equals( $hash, self::fingerprint( $post ) ) ) { return 'changed since preview'; }
		$update = array( 'ID' => $id ); $total = 0;
		foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $field ) {
			$new = self::swap( (string) $post->$field, $spec, $n );
			if ( $n ) { $update[ $field ] = $new; $total += $n; }
		}
		if ( ! $total ) { return 'no longer matches'; }
		$result = wp_update_post( wp_slash( $update ), true );
		return is_wp_error( $result ) ? 'failed: ' . $result->get_error_message() : 'replaced';
	}
}
