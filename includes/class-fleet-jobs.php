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
	const HOOK   = 'brand_fleet_tick';
	const ACTIVE = 'brand_fleet_active_jobs';
	/** Rows per stored chunk; a multiple of STEP so a step never spans two chunks. */
	const CHUNK = 250;
	const STEP  = 25;
	/** Work per cron tick. Cron Control runs one tick at a time, so ticks never overlap. */
	const TICK_SECONDS = 120;

	private static function key( int $user = 0 ): string {
		return 'brand_fleet_job_' . ( $user ? $user : get_current_user_id() );
	}
	private static function chunk_key( array $j, int $n ): string {
		return self::key( (int) $j['user'] ) . '_' . $n;
	}
	private static function replacing( array $j ): bool {
		return 'replace' === ( $j['kind'] ?? '' );
	}
	private static function kind( array $j ): string {
		return (string) ( $j['kind'] ?? 'bulk' );
	}
	/** Find and replace and token-check rows hold match lists (and replace writes posts), so they use smaller chunks and steps; import rows carry per-site values. */
	private static function chunk( array $j ): int {
		return array(
			'replace' => 25,
			'report'  => 25,
			'revert'  => 25,
			'import'  => 100,
			'undo'    => 100,
		)[ self::kind( $j ) ] ??
			self::CHUNK;
	}
	private static function step_size( array $j ): int {
		return array(
			'replace' => 5,
			'report'  => 10,
			'revert'  => 5,
		)[ self::kind( $j ) ] ?? self::STEP;
	}
	/** Kinds whose rows each carry their own values (imports and undos of values). */
	private static function per_site( array $j ): bool {
		return in_array( self::kind( $j ), array( 'import', 'undo' ), true );
	}
	/** The given user's current batch (defaults to the current user). */
	public static function get( int $user = 0 ): array {
		return (array) get_network_option( get_current_network_id(), self::key( $user ), array() );
	}
	private static function store( array $j ): array {
		update_network_option( get_current_network_id(), self::key( (int) $j['user'] ), $j );
		return $j;
	}

	/** One page of result rows. Pre-1.1 batches kept every row inline. */
	public static function rows( array $j, int $offset, int $count = 25 ): array {
		if ( isset( $j['rows'] ) ) {
			return array_slice( (array) $j['rows'], $offset, $count );
		}
		$out = array();
		for (
			$n = intdiv( $offset, self::chunk( $j ) );
			count( $out ) < $count && $n * self::chunk( $j ) < count( $j['ids'] ?? array() );
			++$n
		) {
			foreach ( (array) get_network_option( get_current_network_id(), self::chunk_key( $j, $n ), array() ) as $i => $row ) {
				if ( $i >= $offset && count( $out ) < $count ) {
					$out[] = $row;
				}
			}
		}
		return $out;
	}
	/** One page of rows for sites that had find and replace matches, with each row's index. */
	public static function hit_rows( array $j, int $offset, int $count = 25 ): array {
		$out    = array();
		$chunks = array();
		foreach ( array_slice( (array) ( $j['hits'] ?? array() ), $offset, $count ) as $i ) {
			$n            = intdiv( (int) $i, self::chunk( $j ) );
			$chunks[ $n ] =
				$chunks[ $n ] ?? (array) get_network_option( get_current_network_id(), self::chunk_key( $j, $n ), array() );
			if ( isset( $chunks[ $n ][ $i ] ) ) {
				$out[ (int) $i ] = $chunks[ $n ][ $i ];
			}
		}
		return $out;
	}
	/** Leave one matching post out of (or back in) a reviewed find and replace batch. */
	public static function exclude( string $id, int $row, int $post, bool $excluded ): array {
		return Fleet::locked(
			'job_' . get_current_user_id(),
			static function () use ( $id, $row, $post, $excluded ) {
				if ( ! Fleet::can( 'bulk' ) ) {
					throw new \RuntimeException( 'Bulk update permission required.' );
				}
				$j = self::get();
				if ( ( $j['id'] ?? '' ) !== $id || ! self::replacing( $j ) || 'ready' !== $j['phase'] ) {
					throw new \RuntimeException( 'This preview can no longer be changed. Refresh the page.' );
				}
				$key = $row . ':' . $post;
				if ( $excluded ) {
					$j['left_out'][ $key ] = true;
				} else {
					unset( $j['left_out'][ $key ] );
				}
				if ( count( $j['left_out'] ?? array() ) > 5000 ) {
					throw new \InvalidArgumentException( 'Leave out up to 5,000 pages, or narrow the search.' );
				}
				return self::store( $j );
			},
			20
		);
	}
	/** Row counts by status for a batch. */
	public static function counts( array $j ): array {
		return array_filter( $j['counts'] ?? array_count_values( array_column( (array) ( $j['rows'] ?? array() ), 'status' ) ) );
	}
	/** Compact response for UI and MCP: no site-ID list, one page of rows. */
	public static function view( array $j, int $offset = 0 ): array {
		if ( ! $j ) {
			return array();
		}
		return array(
			'id'                 => $j['id'],
			'kind'               => $j['kind'] ?? 'bulk',
			'undo_of'            => $j['undo'] ?? null,
			'can_undo'           =>
				in_array( $j['phase'], array( 'complete', 'cancelled', 'stopped' ), true ) && Fleet_Undo::available( $j['id'] ),
			'phase'              => $j['phase'],
			'cursor'             => (int) $j['cursor'],
			'total'              => count( $j['ids'] ),
			'patch'              => $j['patch'],
			'replace'            => $j['replace'] ?? null,
			'report'             => $j['report'] ?? null,
			'auto_apply'         => ! empty( $j['auto'] ),
			'sites_with_matches' => count( $j['hits'] ?? array() ),
			'more_sites'         => array_values( $j['more'] ?? array() ),
			'excluded'           => array_keys( $j['left_out'] ?? array() ),
			'counts'             => self::counts( $j ),
			'background'         => in_array( $j['phase'], array( 'preview', 'apply' ), true ),
			'busy'               => ! empty( $j['busy'] ),
			'error'              => $j['error'] ?? '',
			'rows_offset'        => $offset,
			'rows'               => self::rows( $j, $offset ),
		);
	}

	/** Starts a bulk update preview for the given sites and values. */
	public static function start( array $ids, array $patch ): array {
		$j = Fleet::locked( 'job_' . get_current_user_id(), static fn() => self::create( $ids, $patch ) );
		self::activate( $j );
		return $j;
	}
	/** Find and replace batch: $spec comes from Fleet_Replace::spec(). */
	public static function start_replace( array $ids, array $spec ): array {
		$spec = Fleet_Replace::spec( $spec );
		$j    = Fleet::locked( 'job_' . get_current_user_id(), static fn() => self::create( $ids, array(), $spec ) );
		self::activate( $j );
		return $j;
	}
	/** Token check: a read-only scan for {{tokens}} that are unknown or empty on each site. Nothing is ever applied. */
	public static function start_report( array $ids, array $spec ): array {
		$spec = Fleet_Report::spec( $spec );
		$j    = Fleet::locked(
			'job_' . get_current_user_id(),
			static fn() => self::create(
				$ids,
				array(),
				null,
				array(
					'kind'   => 'report',
					'report' => $spec,
				)
			)
		);
		self::activate( $j );
		return $j;
	}
	/** Import: one reviewed batch where every site gets its own values. $plan is site_id => patch. $auto applies without review (scheduled imports). */
	public static function start_import( array $plan, array $source, bool $auto = false ): array {
		$j = Fleet::locked(
			'job_' . get_current_user_id(),
			static fn() => self::create(
				array_keys( $plan ),
				array(),
				null,
				array(
					'kind'   => 'import',
					'import' => $source,
					'auto'   => $auto,
				),
				$plan
			)
		);
		self::activate( $j );
		return $j;
	}
	/** Undo: a reviewed batch putting back what an earlier batch changed. $plan is site_id => old values; $expect holds the values that batch wrote. */
	public static function start_undo( array $ids, array $plan, array $expect, array $source ): array {
		$extra = array(
			'kind' => $source['kind'],
			'undo' => array(
				'batch' => $source['batch'],
				'label' => $source['label'],
			),
		);
		$j     = Fleet::locked(
			'job_' . get_current_user_id(),
			static fn() => self::create( $ids, array(), null, $extra, $plan, $expect )
		);
		self::activate( $j );
		return $j;
	}
	/** Every stored result row of a batch, in order. */
	public static function all_rows( array $j ): array {
		$out = array();
		for ( $n = 0; $n * self::chunk( $j ) < count( $j['ids'] ?? array() ); ++$n ) {
			$out += (array) get_network_option( get_current_network_id(), self::chunk_key( $j, $n ), array() );
		}
		return $out;
	}
	private static function create(
		array $ids,
		array $patch,
		?array $replace = null,
		array $extra = array(),
		array $plan = array(),
		array $expect = array()
	): array {
		if ( ! Fleet::can( 'bulk' ) ) {
			throw new \RuntimeException( 'Bulk update permission required.' );
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( ! $ids || count( $ids ) > 10000 ) {
			throw new \InvalidArgumentException( 'Choose 1–10,000 sites.' );
		}
		if ( ! $patch && ! $replace && ! $extra ) {
			throw new \InvalidArgumentException( 'Choose at least one variable.' );
		}
		// Reject a bad value once, instead of once per site.
		$defs = Fleet::definitions();
		foreach ( $patch as $key => $value ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception is escaped by UI handler; API returns structured errors.
			if ( ! isset( $defs[ $key ] ) || 'network' === $defs[ $key ]['scope'] ) {
				throw new \InvalidArgumentException( 'Unknown or network-locked variable: ' . $key );
			}
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			if ( null !== $value ) {
				Fleet::clean( $value, $defs[ $key ] );
			}
		}
		$old = self::get();
		if ( 'apply' === ( $old['phase'] ?? '' ) ) {
			throw new \RuntimeException(
				sprintf(
					'Your previous batch is still applying (%d of %d sites). Wait for it to finish or cancel it first.',
					(int) $old['cursor'],
					count( $old['ids'] )
				)
			);
		}
		if ( $old && ! isset( $old['rows'] ) ) {
			for ( $n = 0; $n * self::chunk( $old ) < count( $old['ids'] ?? array() ); ++$n ) {
				delete_network_option( get_current_network_id(), self::chunk_key( $old, $n ) );
			}
		}
		$j = array(
			'v'      => 2,
			'id'     => wp_generate_uuid4(),
			'user'   => get_current_user_id(),
			'phase'  => 'preview',
			'ids'    => $ids,
			'patch'  => $patch,
			'cursor' => 0,
			'counts' => array(),
			'sample' => array(),
			'schema' => Fleet::hash( Fleet::definitions() ),
		);
		if ( $replace ) {
			$j += array(
				'kind'     => 'replace',
				'replace'  => $replace,
				'hits'     => array(),
				'left_out' => array(),
				'more'     => array(),
			);
		}
		if ( $extra ) {
			$j += $extra + array( 'hits' => array() );
		}
		if ( $plan ) {
			// Each site's own values travel in its result row, so no single option grows with the fleet.
			$keys = array();
			foreach ( array_chunk( $ids, self::chunk( $j ) ) as $n => $chunk_ids ) {
				$rows = array();
				foreach ( $chunk_ids as $k => $site ) {
					$row = array(
						'site'  => $site,
						'patch' => $plan[ $site ],
					);
					if ( isset( $expect[ $site ] ) ) {
						$row['expect'] = $expect[ $site ];
					}
					$rows[ $n * self::chunk( $j ) + $k ] = $row;
					$keys                               += array_flip( array_keys( $plan[ $site ] ) );
				}
				update_network_option( get_current_network_id(), self::chunk_key( $j, $n ), $rows );
			}
			$j['patch'] = array_fill_keys( array_keys( $keys ), true );
		}
		return self::store( $j );
	}

	/** Manual step of up to 25 sites. Returns current state when the background runner holds the batch or the cursor moved on. */
	public static function step( string $id, int $cursor, bool $confirm = false ): array {
		try {
			return Fleet::locked(
				'job_' . get_current_user_id(),
				static function () use ( $id, $cursor, $confirm ) {
					if ( ! Fleet::can( 'bulk' ) ) {
						throw new \RuntimeException( 'Bulk update permission required.' );
					}
					$j = self::get();
					if ( ( $j['id'] ?? '' ) !== $id ) {
						throw new \RuntimeException( 'Stale batch request; refresh the batch.' );
					}
					if ( (int) $j['cursor'] !== $cursor && ! ( $confirm && 'ready' === $j['phase'] ) ) {
						return $j;
					}
					return self::advance( $j, $confirm, self::step_size( $j ), 20 );
				}
			);
		} catch ( \RuntimeException $e ) {
			if ( Fleet::BUSY !== $e->getCode() ) {
				throw $e;
			}
			$j = self::get();
			if ( ( $j['id'] ?? '' ) !== $id || ( $confirm && 'ready' === $j['phase'] ) ) {
				throw $e;
			}
			return $j + array( 'busy' => true );
		}
	}

	/** Cancels the current user's batch. */
	public static function cancel( string $id ): array {
		return Fleet::locked(
			'job_' . get_current_user_id(),
			static function () use ( $id ) {
				if ( ! Fleet::can( 'bulk' ) ) {
					throw new \RuntimeException( 'Bulk update permission required.' );
				}
				$j = self::get();
				if ( ( $j['id'] ?? '' ) !== $id ) {
					throw new \RuntimeException( 'Stale batch request; refresh the batch.' );
				}
				if ( ! in_array( $j['phase'], array( 'preview', 'ready', 'apply' ), true ) ) {
					return $j;
				}
				return self::finish( $j, 'cancelled' );
			},
			30
		);
	}

	/** Process up to $limit sites or $seconds, checkpointing after every STEP sites. Caller holds the job lock. */
	private static function advance( array $j, bool $confirm, int $limit, float $seconds ): array {
		if ( ! in_array( $j['phase'], array( 'preview', 'ready', 'apply' ), true ) ) {
			return $j;
		}
		if ( isset( $j['rows'] ) ) {
			throw new \RuntimeException( 'This batch was prepared by an older version. Prepare a new preview.' );
		}
		if ( $j['schema'] !== Fleet::hash( Fleet::definitions() ) ) {
			return self::finish( $j, 'stopped', 'Definitions changed. Start a new preview.' );
		}
		if ( 'ready' === $j['phase'] ) {
			if ( ! $confirm ) {
				return $j;
			}
			$j['phase']  = 'apply';
			$j['cursor'] = 0;
			$j['sample'] = array();
			self::store( $j );
			self::activate( $j );
		}
		$total = count( $j['ids'] );
		$done  = 0;
		$start = microtime( true );
		while ( $j['cursor'] < $total && $done < $limit && microtime( true ) - $start < $seconds ) {
			// Applying a find and replace only revisits sites that had matches.
			if ( self::replacing( $j ) && 'apply' === $j['phase'] ) {
				$next = $total;
				foreach ( $j['hits'] as $hit ) {
					if ( $hit >= $j['cursor'] ) {
						$next = min( $next, (int) $hit );
					}
				}
				if ( $next > $j['cursor'] ) {
					$j['cursor'] = $next;
					self::store( $j );
					if ( $next >= $total ) {
						break;
					}
				}
			}
			$n       = intdiv( $j['cursor'], self::chunk( $j ) );
			$chunk   = (array) get_network_option( get_current_network_id(), self::chunk_key( $j, $n ), array() );
			$end     = min( $total, $j['cursor'] + self::step_size( $j ) );
			$updated = array();
			for ( $i = $j['cursor']; $i < $end; ++$i ) {
				$was         = $chunk[ $i ]['status'] ?? '';
				$chunk[ $i ] = self::row( $j, $i, $chunk[ $i ] ?? array() );
				$now         = $chunk[ $i ]['status'];
				if ( $was !== $now ) {
					if ( $was ) {
						$j['counts'][ $was ] = max( 0, ( $j['counts'][ $was ] ?? 0 ) - 1 );
					}
					$j['counts'][ $now ] = ( $j['counts'][ $now ] ?? 0 ) + 1;
				}
				if ( 'updated' === $now && 'ready' === $was && ! self::replacing( $j ) ) {
					$updated[] = (int) $chunk[ $i ]['site'];
				}
				if ( 'preview' === $j['phase'] && ! empty( $chunk[ $i ]['matches'] ) && end( $j['hits'] ) !== $i ) {
					$j['hits'][]  = $i;
					$j['matched'] = ( $j['matched'] ?? 0 ) + count( $chunk[ $i ]['matches'] );
				}
				if (
					'preview' === $j['phase'] &&
					self::replacing( $j ) &&
					! empty( $chunk[ $i ]['more'] ) &&
					! in_array( (int) $chunk[ $i ]['site'], $j['more'] ?? array(), true )
				) {
					$j['more'][] = (int) $chunk[ $i ]['site'];
				}
				if (
					'apply' === $j['phase'] &&
					count( $j['sample'] ) < 20 &&
					in_array( $now, array( 'updated', 'skipped' ), true )
				) {
					$j['sample'][] = array_intersect_key( $chunk[ $i ], array_flip( array( 'site', 'status', 'error' ) ) );
				}
			}
			// Rows first, then the cursor: a crash between the two only repeats work that is safe to repeat.
			update_network_option( get_current_network_id(), self::chunk_key( $j, $n ), $chunk );
			$done       += $end - $j['cursor'];
			$j['cursor'] = $end;
			self::store( $j );
			Fleet_Cache::queue( $updated );
			if ( 'apply' === $j['phase'] && $j['cursor'] < $total ) {
				self::audit( $j );
			}
		}
		if ( $j['cursor'] === $total ) {
			// A token check has nothing to apply; a scheduled import applies without waiting for review.
			if ( 'preview' === $j['phase'] && 'report' === self::kind( $j ) ) {
				return self::finish( $j, 'complete' );
			}
			if ( 'preview' === $j['phase'] && ! empty( $j['auto'] ) ) {
				$j['phase']  = 'apply';
				$j['cursor'] = 0;
				$j['sample'] = array();
				self::store( $j );
				return $limit > $done
					? self::advance( $j, true, $limit - $done, $seconds - ( microtime( true ) - $start ) )
					: $j;
			}
			return self::finish( $j, 'preview' === $j['phase'] ? 'ready' : 'complete' );
		}
		return $j;
	}

	private static function row( array $j, int $i, array $row ): array {
		$site = (int) $j['ids'][ $i ];
		if ( self::replacing( $j ) ) {
			return self::replace_row( $j, $i, $row );
		}
		if ( 'report' === self::kind( $j ) ) {
			return Fleet_Report::row( $site, $j['report'] );
		}
		if ( 'revert' === self::kind( $j ) ) {
			return Fleet_Undo::revert_row( $j, array( 'site' => $site ) + $row );
		}
		$import = self::per_site( $j );
		$undo   = 'undo' === self::kind( $j );
		$patch  = $import ? (array) ( $row['patch'] ?? array() ) : $j['patch'];
		// Undo writes the old values back as they were, including imported ones, and history says why.
		$write = static function ( callable $callback ) use ( $import, $undo ) {
			if ( ! $import ) {
				return $callback();
			}
			return Fleet::via( $undo ? 'undo' : 'import', static fn() => Fleet::import_write( $callback ) );
		};
		try {
			if ( 'preview' === $j['phase'] ) {
				if ( $undo && isset( $row['expect'] ) && Fleet_Undo::changed_since( $site, (array) $row['expect'] ) ) {
					return array(
						'site'   => $site,
						'status' => 'skipped',
						'error'  => 'Changed after the original batch, so it is left as is.',
						'patch'  => $patch,
						'expect' => $row['expect'],
					);
				}
				$before = Fleet::profile( $site );
				$after  = $write( static fn() => Fleet::save_profile( $site, $patch, null, '', true ) );
				// phpcs:disable Universal.Operators.StrictComparisons.LooseNotEqual -- Order-insensitive array comparison.
				$changed =
					array_intersect_key( $before['values'] ?? array(), $patch ) !=
					array_intersect_key( $after['values'] ?? array(), $patch );
				// phpcs:enable Universal.Operators.StrictComparisons.LooseNotEqual
				return array(
					'site'   => $site,
					'before' => array_intersect_key( $before['values'] ?? array(), $patch ),
					'after'  => array_intersect_key( $after['values'] ?? array(), $patch ),
					'hash'   => Fleet::hash( $before ),
					'status' => $import && ! $changed ? 'unchanged' : 'ready',
				) +
					( $import ? array( 'patch' => $patch ) : array() ) +
					( isset( $row['expect'] ) ? array( 'expect' => $row['expect'] ) : array() );
			}
			if ( 'ready' !== ( $row['status'] ?? '' ) ) {
				return $row;
			}
			try {
				$write(
					static fn() => Fleet::bulk_write(
						static fn() => Fleet::save_profile( $site, $patch, null, $row['hash'] )
					)
				);
			} catch ( \RuntimeException $e ) {
				// A checkpoint lost after the write leaves the site already holding exactly the reviewed values.
				if ( ! self::same( array_intersect_key( Fleet::profile( $site )['values'] ?? array(), $patch ), $row['after'] ) ) {
					throw $e;
				}
			}
			return array( 'status' => 'updated' ) + $row;
		} catch ( \Throwable $e ) {
			return array(
				'site'   => $site,
				'status' => 'skipped',
				'error'  => $e->getMessage(),
			) +
				( $import ? array( 'patch' => $patch ) : array() );
		}
	}
	/** Preview lists matching posts on one site; apply rewrites the ones still included and unchanged. */
	private static function replace_row( array $j, int $i, array $row ): array {
		$site = (int) $j['ids'][ $i ];
		if ( 'preview' !== $j['phase'] && 'ready' !== ( $row['status'] ?? '' ) ) {
			return $row;
		}
		if ( ! Fleet::site( $site ) ) {
			return array(
				'site'   => $site,
				'status' => 'skipped',
				'error'  => 'Site is no longer active.',
			);
		}
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- Network-admin batch reads or writes one site's posts; always restored.
		switch_to_blog( $site );
		try {
			if ( 'preview' === $j['phase'] ) {
				$found = Fleet_Replace::find( $j['replace'] );
				return array(
					'site'    => $site,
					'status'  => $found['matches'] ? 'ready' : 'no matches',
					'matches' => $found['matches'],
					'more'    => $found['more'],
				);
			}
			$replaced = 0;
			foreach ( $row['matches'] as $k => $m ) {
				Fleet_Undo::$batch = $j['id'];
				try {
					$result = isset( $j['left_out'][ $i . ':' . $m['post'] ] )
						? 'left out'
						: Fleet_Replace::replace_post( (int) $m['post'], $j['replace'], (string) $m['hash'] );
				} finally {
					Fleet_Undo::$batch = '';
				}
				$row['matches'][ $k ]['result'] = $result;
				if ( 'replaced' === $result ) {
					++$replaced;
				}
			}
			Fleet_Cache::flush();
			return array(
				'status'   => $replaced ? 'updated' : 'skipped',
				'replaced' => $replaced,
			) + $row;
		} catch ( \Throwable $e ) {
			return array(
				'site'   => $site,
				'status' => 'skipped',
				'error'  => $e->getMessage(),
			) + $row;
		} finally {
			restore_current_blog();
		}
	}
	private static function same( array $a, array $b ): bool {
		ksort( $a );
		ksort( $b );
		return $a === $b;
	}

	private static function finish( array $j, string $phase, string $error = '' ): array {
		$applying   = 'apply' === $j['phase'];
		$j['phase'] = $phase;
		if ( $error ) {
			$j['error'] = $error;
		}
		self::store( $j );
		self::deactivate( (int) $j['user'] );
		if ( $applying ) {
			self::audit( $j );
			// Keep what this batch changed so it can be undone; an undo can be offered once.
			Fleet_Undo::capture( $j, self::all_rows( $j ) );
			if ( isset( $j['undo']['batch'] ) ) {
				Fleet_Undo::mark_undone( $j['undo']['batch'], $j['id'] );
			}
		}
		if ( 'stopped' === $phase ) {
			Fleet_Alerts::batch_stopped( $j );
		}
		/**
		 * Fires when a background batch leaves preview or apply.
		 *
		 * @param array  $job      Batch: id, kind (bulk, replace, report, import, undo, revert), user, ids, counts.
		 * @param string $phase    New phase: ready (preview done), complete (applied, or a token check done), cancelled or stopped.
		 * @param bool   $applying True when this batch was applying changes, false when it was previewing.
		 */
		do_action( 'brand_fleet_batch_finished', $j, $phase, $applying );
		return $j;
	}
	/** Activity is a log, not a gate: a busy log never stops a batch. */
	private static function audit( array $j ): void {
		try {
			Fleet::audit_job( $j );
		} catch ( \RuntimeException $e ) {
			if ( Fleet::BUSY !== $e->getCode() ) {
				throw $e;
			}
		}
	}

	private static function activate( array $j ): void {
		Fleet::locked(
			'active',
			static function () use ( $j ) {
				$active                     = (array) get_network_option( get_current_network_id(), self::ACTIVE, array() );
				$active[ (int) $j['user'] ] = true;
				update_network_option( get_current_network_id(), self::ACTIVE, $active );
			},
			30
		);
		self::wake();
	}
	private static function deactivate( int $user ): void {
		Fleet::locked(
			'active',
			static function () use ( $user ) {
				$active = (array) get_network_option( get_current_network_id(), self::ACTIVE, array() );
				unset( $active[ $user ] );
				update_network_option( get_current_network_id(), self::ACTIVE, $active );
			},
			30
		);
	}

	/** Ensure the recurring background tick exists on the main site. */
	public static function wake(): void {
		$main     = (int) get_main_site_id();
		$switched = get_current_blog_id() !== $main;
		// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- Database scope only, so the tick lives on one site; always restored.
		if ( $switched ) {
			switch_to_blog( $main );
		}
		// phpcs:enable WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog
		try {
			if ( ! wp_next_scheduled( self::HOOK ) ) {
				wp_schedule_event( time(), 'brand_fleet_minute', self::HOOK );
			}
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}
	/** Adds the one-minute cron schedule used by background work. */
	public static function schedules( array $schedules ): array {
		$schedules['brand_fleet_minute'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => 'Brand Fleet background work',
		);
		return $schedules;
	}

	/** Background runner: advance each active batch as its owner, then purge caches. Unschedules itself when idle. */
	public static function tick(): void {
		$until    = microtime( true ) + self::TICK_SECONDS;
		$previous = get_current_user_id();
		foreach ( array_keys( (array) get_network_option( get_current_network_id(), self::ACTIVE, array() ) ) as $user ) {
			if ( microtime( true ) >= $until ) {
				break;
			}
			wp_set_current_user( (int) $user );
			try {
				Fleet::locked(
					'job_' . $user,
					static function () use ( $user, $until ) {
						$j = self::get( (int) $user );
						if ( ! $j || ! in_array( $j['phase'], array( 'preview', 'apply' ), true ) ) {
							self::deactivate( (int) $user );
							return;
						}
						// An owner who lost network access stops their batch; it never writes as someone else.
						if ( ! Fleet::can( 'bulk' ) ) {
							self::finish( $j, 'stopped', 'The batch owner no longer has bulk update permission.' );
							return;
						}
						try {
							self::advance( $j, false, PHP_INT_MAX, $until - microtime( true ) );
						} catch ( \RuntimeException $e ) {
							self::finish( self::get( (int) $user ), 'stopped', $e->getMessage() );
						}
					}
				);
			} catch ( \RuntimeException $e ) {
				if ( Fleet::BUSY !== $e->getCode() ) {
					throw $e;
				}
			} finally {
				wp_set_current_user( $previous );
			}
		}
		Fleet_Segments::rebuild( max( 1, $until - microtime( true ) ) );
		Fleet_Cache::drain( $until - microtime( true ) );
		Fleet::index_managed_sites( 1000 );
		Fleet_Overrides::backfill( 1000 );
		Fleet_Undo::cleanup( max( 1, $until - microtime( true ) ) );
		if (
			get_network_option( get_current_network_id(), self::ACTIVE, array() ) ||
			Fleet_Cache::pending() ||
			Fleet_Segments::pending() ||
			Fleet_Undo::pending_cleanup() ||
			! Fleet_Overrides::indexed()
		) {
			return;
		}
		// Idle: stop ticking until wake() is called again.
		wp_clear_scheduled_hook( self::HOOK );
	}
}
