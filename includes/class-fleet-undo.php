<?php
/**
 * Undo for applied batches. When a bulk update, import or find and replace stops
 * applying, what it changed is kept for the newest 10 batches: values as before / after
 * pairs per site (in fixed-size chunks), and replaced pages as a snapshot on each post.
 * Undoing is itself a reviewed batch: preview first, and anything edited since the
 * original batch is skipped, never overwritten.
 */
namespace BrandFleet;

defined( 'ABSPATH' ) || exit;
class Fleet_Undo {
	const INDEX = 'brand_fleet_undo';
	const KEEP = 10;
	const CHUNK = 250;
	/** Sites whose replace snapshots outlived their undo window, cleaned up by the background runner. */
	const CLEANUP = 'brand_fleet_undo_cleanup';
	/** Set while a find and replace applies, so each replaced post keeps a snapshot. */
	public static string $batch = '';

	private static function tag( string $batch ): string { return substr( (string) preg_replace( '/[^a-f0-9]/', '', $batch ), 0, 12 ); }
	public static function meta_key( string $batch ): string { return '_brand_fleet_undo_' . self::tag( $batch ); }
	private static function chunk_key( string $batch, int $n ): string { return self::INDEX . '_' . self::tag( $batch ) . '_' . $n; }

	public static function index(): array { return (array) get_network_option( get_current_network_id(), self::INDEX, array() ); }
	public static function entry( string $batch ): array { return (array) ( self::index()[ $batch ] ?? array() ); }
	/** An undo is offered once, while the batch is among the newest 10 and has not been undone. */
	public static function available( string $batch ): bool { $e = self::entry( $batch ); return $e && empty( $e['undone'] ); }

	/** Snapshot one post just before a find and replace rewrites it. $after is filled in once the write lands. */
	public static function snapshot( \WP_Post $post, array $fields, array $meta ): void {
		if ( '' === self::$batch ) { return; }
		$old = array();
		foreach ( array_keys( $fields ) as $field ) { $old[ $field ] = (string) $post->$field; }
		$was = array();
		foreach ( array_keys( $meta ) as $key ) { $was[ $key ] = (string) get_post_meta( $post->ID, $key, true ); }
		update_post_meta( $post->ID, self::meta_key( self::$batch ), wp_slash( array( 'fields' => $old, 'meta' => $was ) ) );
	}
	public static function snapshot_done( int $id ): void {
		if ( '' === self::$batch ) { return; }
		$snap = get_post_meta( $id, self::meta_key( self::$batch ), true );
		if ( ! is_array( $snap ) ) { return; }
		clean_post_cache( $id );
		$snap['after'] = self::state( $id, array_keys( $snap['fields'] ), array_keys( $snap['meta'] ) );
		update_post_meta( $id, self::meta_key( self::$batch ), wp_slash( $snap ) );
	}
	private static function state( int $id, array $fields, array $meta ): string {
		$post = get_post( $id ); $parts = array();
		foreach ( $fields as $field ) { $parts[] = $post ? (string) $post->$field : ''; }
		foreach ( $meta as $key ) { $parts[] = (string) get_post_meta( $id, $key, true ); }
		return hash( 'sha256', implode( "\0", $parts ) );
	}

	/** Called once when a batch stops applying: keep what it changed. */
	public static function capture( array $j, array $rows ): void {
		$kind = (string) ( $j['kind'] ?? 'bulk' );
		if ( ! in_array( $kind, array( 'bulk', 'import', 'replace', 'undo' ), true ) ) { return; }
		$keep = array();
		foreach ( $rows as $row ) {
			if ( 'updated' !== ( $row['status'] ?? '' ) ) { continue; }
			if ( 'replace' === $kind ) { if ( ! empty( $row['replaced'] ) ) { $keep[] = (int) $row['site']; } continue; }
			$keys = array_keys( 'bulk' === $kind ? (array) $j['patch'] : (array) ( $row['patch'] ?? array() ) );
			$pick = static fn( array $values ) => array_combine( $keys, array_map( static fn( $key ) => $values[ $key ] ?? null, $keys ) );
			$keep[] = array( 's' => (int) $row['site'], 'b' => $pick( (array) ( $row['before'] ?? array() ) ), 'a' => $pick( (array) ( $row['after'] ?? array() ) ) );
		}
		if ( ! $keep ) { return; }
		$network = get_current_network_id(); $chunks = 0;
		foreach ( array_chunk( $keep, self::CHUNK ) as $n => $chunk ) { update_network_option( $network, self::chunk_key( $j['id'], $n ), $chunk ); $chunks = $n + 1; }
		Fleet::locked( 'undo', static function () use ( $j, $kind, $keep, $chunks, $network ) {
			$index = self::index();
			$index[ $j['id'] ] = array( 'kind' => $kind, 'label' => self::label( $j ), 'user' => (int) $j['user'], 'time' => gmdate( 'c' ), 'sites' => count( $keep ), 'chunks' => $chunks );
			while ( count( $index ) > self::KEEP ) {
				$old = (string) array_key_first( $index ); $e = $index[ $old ]; unset( $index[ $old ] );
				if ( 'replace' === $e['kind'] ) { self::queue_cleanup( $old ); }
				for ( $n = 0; $n < (int) $e['chunks']; ++$n ) { delete_network_option( $network, self::chunk_key( $old, $n ) ); }
			}
			update_network_option( $network, self::INDEX, $index );
		}, 30 );
	}
	public static function label( array $j ): string {
		$defs = Fleet::definitions(); $labels = implode( ', ', array_map( static fn( $key ) => $defs[ $key ]['label'] ?? $key, array_keys( (array) $j['patch'] ) ) );
		switch ( $j['kind'] ?? 'bulk' ) {
			case 'replace': return sprintf( 'Find & replace “%s” → “%s”', $j['replace']['find'], $j['replace']['with'] );
			case 'import': return 'Import from ' . ( $j['import']['label'] ?? 'a spreadsheet' );
			case 'undo': case 'revert': return 'Undo of ' . ( $j['undo']['label'] ?? 'a batch' );
			default: return 'Bulk update: ' . $labels;
		}
	}

	/** Every kept row for a batch. */
	public static function rows( string $batch ): array {
		$e = self::entry( $batch ); $rows = array();
		for ( $n = 0; $n < (int) ( $e['chunks'] ?? 0 ); ++$n ) { $rows = array_merge( $rows, (array) get_network_option( get_current_network_id(), self::chunk_key( $batch, $n ), array() ) ); }
		return $rows;
	}

	/** Start the reviewed undo batch for an applied batch. */
	public static function start( string $batch ): array {
		if ( ! Fleet::can( 'bulk' ) ) { throw new \RuntimeException( 'Bulk update permission required.' ); }
		if ( ! self::available( $batch ) ) { throw new \InvalidArgumentException( 'This batch can no longer be undone: only the newest 10 batches can, once each.' ); }
		$e = self::entry( $batch ); $rows = self::rows( $batch );
		$source = array( 'batch' => $batch, 'label' => $e['label'] );
		if ( 'replace' === $e['kind'] ) { return Fleet_Jobs::start_undo( array_map( 'intval', $rows ), array(), array(), $source + array( 'kind' => 'revert' ) ); }
		$plan = array(); $expect = array();
		foreach ( $rows as $row ) { $plan[ (int) $row['s'] ] = $row['b']; $expect[ (int) $row['s'] ] = $row['a']; }
		return Fleet_Jobs::start_undo( array_keys( $plan ), $plan, $expect, $source + array( 'kind' => 'undo' ) );
	}
	public static function mark_undone( string $batch, string $by ): void {
		Fleet::locked( 'undo', static function () use ( $batch, $by ) {
			$index = self::index();
			if ( ! isset( $index[ $batch ] ) ) { return; }
			$index[ $batch ]['undone'] = $by;
			update_network_option( get_current_network_id(), self::INDEX, $index );
		}, 30 );
	}

	/** Undo preview row for values: skip a site whose values changed after the original batch. */
	public static function changed_since( int $site, array $expect ): bool {
		$own = (array) ( Fleet::profile( $site )['values'] ?? array() );
		foreach ( $expect as $key => $value ) { if ( ( $own[ $key ] ?? null ) !== $value ) { return true; } }
		return false;
	}

	/** Undo of a find and replace, on one site: list pages with a snapshot, then put them back. */
	public static function revert_row( array $j, array $row ): array {
		$site = (int) $row['site'];
		if ( 'preview' !== $j['phase'] && 'ready' !== ( $row['status'] ?? '' ) ) { return $row; }
		if ( ! Fleet::site( $site ) ) { return array( 'site' => $site, 'status' => 'skipped', 'error' => 'Site is no longer active.' ); }
		$key = self::meta_key( $j['undo']['batch'] );
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- Background batch reads or restores one site's posts; always restored.
		switch_to_blog( $site );
		try {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Exact postmeta meta_key lookup (indexed); a find and replace changes at most MAX_MATCHES posts per site.
			$ids = get_posts( array( 'post_type' => array_keys( Fleet_Replace::TYPES ), 'post_status' => 'any', 'meta_key' => $key, 'fields' => 'ids', 'numberposts' => Fleet_Replace::MAX_MATCHES, 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => false ) );
			if ( 'preview' === $j['phase'] ) {
				$matches = array();
				foreach ( $ids as $id ) {
					$snap = get_post_meta( $id, $key, true );
					if ( ! is_array( $snap ) ) { continue; }
					$changed = ( $snap['after'] ?? '' ) !== self::state( $id, array_keys( $snap['fields'] ), array_keys( $snap['meta'] ) );
					$matches[] = array( 'post' => (int) $id, 'title' => mb_substr( wp_strip_all_tags( get_the_title( $id ) ), 0, 80 ), 'type' => get_post_type( $id ), 'changed' => $changed );
				}
				return array( 'site' => $site, 'status' => array_filter( $matches, static fn( $m ) => ! $m['changed'] ) ? 'ready' : 'nothing to undo', 'matches' => $matches );
			}
			$restored = 0;
			foreach ( (array) ( $row['matches'] ?? array() ) as $k => $m ) {
				$snap = get_post_meta( (int) $m['post'], $key, true );
				if ( ! is_array( $snap ) || ( $snap['after'] ?? '' ) !== self::state( (int) $m['post'], array_keys( $snap['fields'] ), array_keys( $snap['meta'] ) ) ) { $row['matches'][ $k ]['result'] = 'changed since, left as is'; continue; }
				if ( $snap['fields'] ) {
					$result = wp_update_post( wp_slash( array( 'ID' => (int) $m['post'] ) + $snap['fields'] ), true );
					if ( is_wp_error( $result ) ) { $row['matches'][ $k ]['result'] = 'failed: ' . $result->get_error_message(); continue; }
				}
				foreach ( $snap['meta'] as $meta_key => $value ) { update_post_meta( (int) $m['post'], $meta_key, wp_slash( $value ) ); }
				delete_post_meta( (int) $m['post'], $key );
				clean_post_cache( (int) $m['post'] );
				$row['matches'][ $k ]['result'] = 'restored'; ++$restored;
			}
			Fleet_Cache::flush();
			return array( 'status' => $restored ? 'updated' : 'skipped', 'replaced' => $restored ) + $row;
		} catch ( \Throwable $e ) {
			return array( 'site' => $site, 'status' => 'skipped', 'error' => $e->getMessage() ) + $row;
		} finally {
			restore_current_blog();
		}
	}

	private static function queue_cleanup( string $batch ): void {
		$queue = (array) get_network_option( get_current_network_id(), self::CLEANUP, array() );
		$queue[ self::meta_key( $batch ) ] = array_map( 'intval', self::rows( $batch ) );
		update_network_option( get_current_network_id(), self::CLEANUP, $queue );
	}
	public static function pending_cleanup(): bool { return (bool) get_network_option( get_current_network_id(), self::CLEANUP, array() ); }
	/** Background: delete snapshots of batches that can no longer be undone, within a time budget. */
	public static function cleanup( float $seconds ): void {
		$until = microtime( true ) + $seconds; $network = get_current_network_id();
		$queue = (array) get_network_option( $network, self::CLEANUP, array() );
		foreach ( $queue as $key => $sites ) {
			while ( $sites && microtime( true ) < $until ) {
				$site = (int) array_shift( $sites );
				if ( ! Fleet::site( $site ) ) { continue; }
				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- Deletes one hidden post meta key on one site; always restored.
				switch_to_blog( $site );
				try { delete_post_meta_by_key( (string) $key ); } finally { restore_current_blog(); }
			}
			if ( $sites ) { $queue[ $key ] = $sites; break; }
			unset( $queue[ $key ] );
		}
		update_network_option( $network, self::CLEANUP, $queue );
	}
}
