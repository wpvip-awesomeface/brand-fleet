<?php
/**
 * User-owned, preview-before-apply batches.
 *
 * Results are stored in fixed-size chunks so no single option grows with the fleet,
 * and a recurring VIP Cron Control event finishes previews and applies in the
 * background. Browsers and MCP clients may step a batch too; the per-user lock keeps
 * the two from overlapping.
 */
namespace BrandFleet;

defined( 'ABSPATH' ) || exit;
class Fleet_Jobs {
	const HOOK = 'brand_fleet_tick';
	const ACTIVE = 'brand_fleet_active_jobs';
	/** Rows per stored chunk; a multiple of STEP so a step never spans two chunks. */
	const CHUNK = 250;
	const STEP = 25;
	/** Work per cron tick. Cron Control runs one tick at a time, so ticks never overlap. */
	const TICK_SECONDS = 120;

	private static function key( int $user = 0 ): string { return 'brand_fleet_job_' . ( $user ? $user : get_current_user_id() ); }
	private static function chunk_key( array $j, int $n ): string { return self::key( (int) $j['user'] ) . '_' . $n; }
	public static function get( int $user = 0 ): array { return (array) get_network_option( get_current_network_id(), self::key( $user ), array() ); }
	private static function store( array $j ): array { update_network_option( get_current_network_id(), self::key( (int) $j['user'] ), $j ); return $j; }

	/** One page of result rows. Pre-1.1 batches kept every row inline. */
	public static function rows( array $j, int $offset, int $count = 25 ): array {
		if ( isset( $j['rows'] ) ) { return array_slice( (array) $j['rows'], $offset, $count ); }
		$out = array();
		for ( $n = intdiv( $offset, self::CHUNK ); count( $out ) < $count && $n * self::CHUNK < count( $j['ids'] ?? array() ); ++$n ) {
			foreach ( (array) get_network_option( get_current_network_id(), self::chunk_key( $j, $n ), array() ) as $i => $row ) {
				if ( $i >= $offset && count( $out ) < $count ) { $out[] = $row; }
			}
		}
		return $out;
	}
	public static function counts( array $j ): array {
		return array_filter( $j['counts'] ?? array_count_values( array_column( (array) ( $j['rows'] ?? array() ), 'status' ) ) );
	}
	/** Compact response for UI and MCP: no site-ID list, one page of rows. */
	public static function view( array $j, int $offset = 0 ): array {
		if ( ! $j ) { return array(); }
		return array( 'id' => $j['id'], 'phase' => $j['phase'], 'cursor' => (int) $j['cursor'], 'total' => count( $j['ids'] ), 'patch' => $j['patch'], 'counts' => self::counts( $j ), 'background' => in_array( $j['phase'], array( 'preview', 'apply' ), true ), 'busy' => ! empty( $j['busy'] ), 'error' => $j['error'] ?? '', 'rows_offset' => $offset, 'rows' => self::rows( $j, $offset ) );
	}

	public static function start( array $ids, array $patch ): array {
		$j = Fleet::locked( 'job_' . get_current_user_id(), static fn() => self::create( $ids, $patch ) );
		self::activate( $j );
		return $j;
	}
	private static function create( array $ids, array $patch ): array {
		if ( ! Fleet::network_admin() ) { throw new \RuntimeException( 'Network permission required.' ); }
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( ! $ids || count( $ids ) > 10000 || ! $patch ) { throw new \InvalidArgumentException( 'Choose 1–10,000 sites and at least one variable.' ); }
		// Reject a bad value once, instead of once per site.
		$defs = Fleet::definitions();
		foreach ( $patch as $key => $value ) {
			if ( ! isset( $defs[ $key ] ) || 'network' === $defs[ $key ]['scope'] ) { throw new \InvalidArgumentException( 'Unknown or network-locked variable: ' . $key ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception is escaped by UI handler; API returns structured errors.
			if ( null !== $value ) { Fleet::clean( $value, $defs[ $key ] ); }
		}
		$old = self::get();
		if ( 'apply' === ( $old['phase'] ?? '' ) ) { throw new \RuntimeException( sprintf( 'Your previous batch is still applying (%d of %d sites). Wait for it to finish or cancel it first.', (int) $old['cursor'], count( $old['ids'] ) ) ); }
		if ( $old && ! isset( $old['rows'] ) ) {
			for ( $n = 0; $n * self::CHUNK < count( $old['ids'] ?? array() ); ++$n ) { delete_network_option( get_current_network_id(), self::chunk_key( $old, $n ) ); }
		}
		return self::store( array( 'v' => 2, 'id' => wp_generate_uuid4(), 'user' => get_current_user_id(), 'phase' => 'preview', 'ids' => $ids, 'patch' => $patch, 'cursor' => 0, 'counts' => array(), 'sample' => array(), 'schema' => Fleet::hash( Fleet::definitions() ) ) );
	}

	/** Manual step of up to 25 sites. Returns current state when the background runner holds the batch or the cursor moved on. */
	public static function step( string $id, int $cursor, bool $confirm = false ): array {
		try {
			return Fleet::locked( 'job_' . get_current_user_id(), static function () use ( $id, $cursor, $confirm ) {
				if ( ! Fleet::network_admin() ) { throw new \RuntimeException( 'Network permission required.' ); }
				$j = self::get();
				if ( ( $j['id'] ?? '' ) !== $id ) { throw new \RuntimeException( 'Stale batch request; refresh the batch.' ); }
				if ( (int) $j['cursor'] !== $cursor && ! ( $confirm && 'ready' === $j['phase'] ) ) { return $j; }
				return self::advance( $j, $confirm, self::STEP, 20 );
			} );
		} catch ( \RuntimeException $e ) {
			if ( Fleet::BUSY !== $e->getCode() ) { throw $e; }
			$j = self::get();
			if ( ( $j['id'] ?? '' ) !== $id || ( $confirm && 'ready' === $j['phase'] ) ) { throw $e; }
			return $j + array( 'busy' => true );
		}
	}

	public static function cancel( string $id ): array {
		return Fleet::locked( 'job_' . get_current_user_id(), static function () use ( $id ) {
			if ( ! Fleet::network_admin() ) { throw new \RuntimeException( 'Network permission required.' ); }
			$j = self::get();
			if ( ( $j['id'] ?? '' ) !== $id ) { throw new \RuntimeException( 'Stale batch request; refresh the batch.' ); }
			if ( ! in_array( $j['phase'], array( 'preview', 'ready', 'apply' ), true ) ) { return $j; }
			return self::finish( $j, 'cancelled' );
		}, 30 );
	}

	/** Process up to $limit sites or $seconds, checkpointing after every STEP sites. Caller holds the job lock. */
	private static function advance( array $j, bool $confirm, int $limit, float $seconds ): array {
		if ( ! in_array( $j['phase'], array( 'preview', 'ready', 'apply' ), true ) ) { return $j; }
		if ( isset( $j['rows'] ) ) { throw new \RuntimeException( 'This batch was prepared by an older version. Prepare a new preview.' ); }
		if ( $j['schema'] !== Fleet::hash( Fleet::definitions() ) ) { return self::finish( $j, 'stopped', 'Definitions changed. Start a new preview.' ); }
		if ( 'ready' === $j['phase'] ) {
			if ( ! $confirm ) { return $j; }
			$j['phase'] = 'apply'; $j['cursor'] = 0; $j['sample'] = array();
			self::store( $j ); self::activate( $j );
		}
		$total = count( $j['ids'] ); $done = 0; $start = microtime( true );
		while ( $j['cursor'] < $total && $done < $limit && microtime( true ) - $start < $seconds ) {
			$n = intdiv( $j['cursor'], self::CHUNK );
			$chunk = (array) get_network_option( get_current_network_id(), self::chunk_key( $j, $n ), array() );
			$end = min( $total, $j['cursor'] + self::STEP ); $updated = array();
			for ( $i = $j['cursor']; $i < $end; ++$i ) {
				$was = $chunk[ $i ]['status'] ?? '';
				$chunk[ $i ] = self::row( $j, $i, $chunk[ $i ] ?? array() );
				$now = $chunk[ $i ]['status'];
				if ( $was !== $now ) {
					if ( $was ) { $j['counts'][ $was ] = max( 0, ( $j['counts'][ $was ] ?? 0 ) - 1 ); }
					$j['counts'][ $now ] = ( $j['counts'][ $now ] ?? 0 ) + 1;
				}
				if ( 'updated' === $now && 'ready' === $was ) { $updated[] = (int) $chunk[ $i ]['site']; }
				if ( 'apply' === $j['phase'] && count( $j['sample'] ) < 20 && in_array( $now, array( 'updated', 'skipped' ), true ) ) { $j['sample'][] = array_intersect_key( $chunk[ $i ], array_flip( array( 'site', 'status', 'error' ) ) ); }
			}
			// Rows first, then the cursor: a crash between the two only repeats work that is safe to repeat.
			update_network_option( get_current_network_id(), self::chunk_key( $j, $n ), $chunk );
			$done += $end - $j['cursor']; $j['cursor'] = $end;
			self::store( $j );
			Fleet_Cache::queue( $updated );
			if ( 'apply' === $j['phase'] && $j['cursor'] < $total ) { self::audit( $j ); }
		}
		if ( $j['cursor'] === $total ) { return self::finish( $j, 'preview' === $j['phase'] ? 'ready' : 'complete' ); }
		return $j;
	}

	private static function row( array $j, int $i, array $row ): array {
		$site = (int) $j['ids'][ $i ];
		try {
			if ( 'preview' === $j['phase'] ) {
				$before = Fleet::profile( $site );
				$after = Fleet::save_profile( $site, $j['patch'], null, '', true );
				return array( 'site' => $site, 'before' => array_intersect_key( $before['values'] ?? array(), $j['patch'] ), 'after' => array_intersect_key( $after['values'] ?? array(), $j['patch'] ), 'hash' => Fleet::hash( $before ), 'status' => 'ready' );
			}
			if ( 'ready' !== ( $row['status'] ?? '' ) ) { return $row; }
			try {
				Fleet::bulk_write( static fn() => Fleet::save_profile( $site, $j['patch'], null, $row['hash'] ) );
			} catch ( \RuntimeException $e ) {
				// A checkpoint lost after the write leaves the site already holding exactly the reviewed values.
				if ( ! self::same( array_intersect_key( Fleet::profile( $site )['values'] ?? array(), $j['patch'] ), $row['after'] ) ) { throw $e; }
			}
			return array( 'status' => 'updated' ) + $row;
		} catch ( \Throwable $e ) {
			return array( 'site' => $site, 'status' => 'skipped', 'error' => $e->getMessage() );
		}
	}
	private static function same( array $a, array $b ): bool { ksort( $a ); ksort( $b ); return $a === $b; }

	private static function finish( array $j, string $phase, string $error = '' ): array {
		$applying = 'apply' === $j['phase'];
		$j['phase'] = $phase;
		if ( $error ) { $j['error'] = $error; }
		self::store( $j ); self::deactivate( (int) $j['user'] );
		if ( $applying ) { self::audit( $j ); }
		return $j;
	}
	/** Activity is a log, not a gate: a busy log never stops a batch. */
	private static function audit( array $j ): void {
		try {
			Fleet::audit_job( $j );
		} catch ( \RuntimeException $e ) {
			if ( Fleet::BUSY !== $e->getCode() ) { throw $e; }
		}
	}

	private static function activate( array $j ): void {
		Fleet::locked( 'active', static function () use ( $j ) {
			$active = (array) get_network_option( get_current_network_id(), self::ACTIVE, array() );
			$active[ (int) $j['user'] ] = true;
			update_network_option( get_current_network_id(), self::ACTIVE, $active );
		}, 30 );
		self::wake();
	}
	private static function deactivate( int $user ): void {
		Fleet::locked( 'active', static function () use ( $user ) {
			$active = (array) get_network_option( get_current_network_id(), self::ACTIVE, array() );
			unset( $active[ $user ] );
			update_network_option( get_current_network_id(), self::ACTIVE, $active );
		}, 30 );
	}

	/** Ensure the recurring background tick exists on the main site. */
	public static function wake(): void {
		$main = (int) get_main_site_id(); $switched = get_current_blog_id() !== $main;
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- Database scope only, so the tick lives on one site; always restored.
		if ( $switched ) { switch_to_blog( $main ); }
		try {
			if ( ! wp_next_scheduled( self::HOOK ) ) { wp_schedule_event( time(), 'brand_fleet_minute', self::HOOK ); }
		} finally {
			if ( $switched ) { restore_current_blog(); }
		}
	}
	public static function schedules( array $schedules ): array {
		$schedules['brand_fleet_minute'] = array( 'interval' => MINUTE_IN_SECONDS, 'display' => 'Brand Fleet background work' );
		return $schedules;
	}

	/** Background runner: advance each active batch as its owner, then purge caches. Unschedules itself when idle. */
	public static function tick(): void {
		$until = microtime( true ) + self::TICK_SECONDS; $previous = get_current_user_id();
		foreach ( array_keys( (array) get_network_option( get_current_network_id(), self::ACTIVE, array() ) ) as $user ) {
			if ( microtime( true ) >= $until ) { break; }
			wp_set_current_user( (int) $user );
			try {
				Fleet::locked( 'job_' . $user, static function () use ( $user, $until ) {
					$j = self::get( (int) $user );
					if ( ! $j || ! in_array( $j['phase'], array( 'preview', 'apply' ), true ) ) { self::deactivate( (int) $user ); return; }
					// An owner who lost network access stops their batch; it never writes as someone else.
					if ( ! Fleet::network_admin() ) { self::finish( $j, 'stopped', 'The batch owner no longer has network permission.' ); return; }
					try {
						self::advance( $j, false, PHP_INT_MAX, $until - microtime( true ) );
					} catch ( \RuntimeException $e ) {
						self::finish( self::get( (int) $user ), 'stopped', $e->getMessage() );
					}
				} );
			} catch ( \RuntimeException $e ) {
				if ( Fleet::BUSY !== $e->getCode() ) { throw $e; }
			} finally {
				wp_set_current_user( $previous );
			}
		}
		Fleet_Cache::drain( $until - microtime( true ) );
		Fleet::index_managed_sites( 1000 );
		if ( get_network_option( get_current_network_id(), self::ACTIVE, array() ) || Fleet_Cache::pending() ) { return; }
		// Idle: stop ticking until wake() is called again.
		wp_clear_scheduled_hook( self::HOOK );
	}
}
