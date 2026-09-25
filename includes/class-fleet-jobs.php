<?php
/** User-owned, preview-before-apply batches; no fleet-sized write request. */
namespace BrandFleet;

defined( 'ABSPATH' ) || exit;
class Fleet_Jobs {
	private static function key(): string { return 'brand_fleet_job_' . get_current_user_id(); }
	public static function get(): array { return (array) get_network_option( get_current_network_id(), self::key(), array() ); }
	private static function store( array $job ): array { update_network_option( get_current_network_id(), self::key(), $job ); return $job; }
	public static function start( array $ids, array $patch ): array {
		return Fleet::locked( 'job_' . get_current_user_id(), static fn() => self::create( $ids, $patch ) );
	}
	private static function create( array $ids, array $patch ): array {
		if ( ! Fleet::network_admin() ) { throw new \RuntimeException( 'Network permission required.' ); }
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( ! $ids || count( $ids ) > 10000 || ! $patch ) { throw new \InvalidArgumentException( 'Choose 1–10,000 sites and at least one variable.' ); }
		return self::store( array( 'id' => wp_generate_uuid4(), 'phase' => 'preview', 'ids' => $ids, 'patch' => $patch, 'cursor' => 0, 'rows' => array(), 'schema' => Fleet::hash( Fleet::definitions() ) ) );
	}
	public static function step( string $id, int $cursor, bool $confirm = false ): array {
		return Fleet::locked( 'job_' . get_current_user_id(), static fn() => self::process( $id, $cursor, $confirm ) );
	}
	private static function process( string $id, int $cursor, bool $confirm ): array {
		if ( ! Fleet::network_admin() ) { throw new \RuntimeException( 'Network permission required.' ); }
		$j = self::get();
		if ( ( $j['id'] ?? '' ) !== $id || (int) $j['cursor'] !== $cursor ) { throw new \RuntimeException( 'Stale batch request; refresh the batch.' ); }
		if ( $j['schema'] !== Fleet::hash( Fleet::definitions() ) ) { throw new \RuntimeException( 'Definitions changed. Start a new preview.' ); }
		if ( 'ready' === $j['phase'] ) {
			if ( ! $confirm ) { return $j; }
			$j['phase'] = 'apply'; $j['cursor'] = 0;
		}
		if ( ! in_array( $j['phase'], array( 'preview', 'apply' ), true ) ) { return $j; }
		$end = min( count( $j['ids'] ), $j['cursor'] + 25 );
		for ( $i = $j['cursor']; $i < $end; ++$i ) {
			$site = $j['ids'][ $i ];
			try {
				if ( 'preview' === $j['phase'] ) {
					$before = Fleet::profile( $site );
					$after = Fleet::save_profile( $site, $j['patch'], null, '', true );
					$j['rows'][ $i ] = array( 'site' => $site, 'before' => array_intersect_key( $before['values'] ?? array(), $j['patch'] ), 'after' => array_intersect_key( $after['values'] ?? array(), $j['patch'] ), 'hash' => Fleet::hash( $before ), 'status' => 'ready' );
				} elseif ( 'ready' === ( $j['rows'][ $i ]['status'] ?? '' ) ) {
					Fleet::bulk_write( static fn() => Fleet::save_profile( $site, $j['patch'], null, $j['rows'][ $i ]['hash'] ) );
					$j['rows'][ $i ]['status'] = 'updated';
				}
			} catch ( \Throwable $e ) { $j['rows'][ $i ] = array( 'site' => $site, 'status' => 'skipped', 'error' => $e->getMessage() ); }
			// Checkpoint after every site so an interrupted batch is resumable.
			$j['cursor'] = $i + 1; self::store( $j );
			if ( 'apply' === $j['phase'] ) { Fleet::audit_job( $j ); }
		}
		if ( $j['cursor'] === count( $j['ids'] ) ) { $j['phase'] = 'preview' === $j['phase'] ? 'ready' : 'complete'; }
		if ( 'complete' === $j['phase'] ) { Fleet::audit_job( $j ); }
		return self::store( $j );
	}
}
