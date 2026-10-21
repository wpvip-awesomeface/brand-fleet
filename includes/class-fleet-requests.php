<?php
/**
 * Change requests: a site admin asks for a new value for something the network
 * controls (a phone number, hours, a logo), and a fleet editor approves or declines it.
 * Approving writes the value through the normal save, so history, overrides and cache
 * purges all follow.
 */
namespace BrandFleet;

defined( 'ABSPATH' ) || exit;
class Fleet_Requests {
	const OPTION = 'brand_fleet_requests';
	const MAX_OPEN = 500;
	const MAX_PER_SITE = 20;
	const KEEP_DONE = 100;

	public static function all(): array {
		return array_values( array_filter( (array) get_network_option( get_current_network_id(), self::OPTION, array() ), 'is_array' ) );
	}
	public static function pending(): array { return array_values( array_filter( self::all(), static fn( $r ) => 'pending' === $r['status'] ) ); }
	public static function for_site( int $site ): array { return array_slice( array_reverse( array_values( array_filter( self::all(), static fn( $r ) => (int) $r['site'] === $site ) ) ), 0, 10 ); }

	/** Variables a site admin can ask about: per-site values the network keeps for itself. */
	public static function requestable( int $site ): array {
		return array_filter( Fleet::definitions(), static fn( $d ) => 'location' === $d['scope'] && 'embed' !== $d['type'] && ! Fleet::editable( $d, $site ) );
	}

	public static function submit( int $site, string $key, string $value, string $note ): array {
		if ( ! Fleet::site( $site ) || ! Fleet::profile( $site ) || ! current_user_can_for_site( $site, 'manage_options' ) ) { throw new \RuntimeException( 'Only this site\'s administrators can ask for changes.' ); }
		$defs = self::requestable( $site );
		if ( ! isset( $defs[ $key ] ) ) { throw new \InvalidArgumentException( 'You can edit this value yourself, or it is the same on every site.' ); }
		$request = array( 'id' => substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 ), 'site' => $site, 'key' => $key, 'value' => Fleet::clean( $value, $defs[ $key ] ), 'note' => mb_substr( sanitize_textarea_field( $note ), 0, 500 ), 'user' => get_current_user_id(), 'time' => gmdate( 'c' ), 'status' => 'pending' );
		self::write( static function ( array $all ) use ( $request ) {
			// A newer request for the same value replaces the older pending one.
			foreach ( $all as $i => $r ) { if ( 'pending' === $r['status'] && (int) $r['site'] === $request['site'] && $r['key'] === $request['key'] ) { $all[ $i ]['status'] = 'replaced'; } }
			if ( count( array_filter( $all, static fn( $r ) => 'pending' === $r['status'] && (int) $r['site'] === $request['site'] ) ) >= self::MAX_PER_SITE ) { throw new \RuntimeException( 'This site already has 20 requests waiting. Try again after some are answered.' ); }
			if ( count( array_filter( $all, static fn( $r ) => 'pending' === $r['status'] ) ) >= self::MAX_OPEN ) { throw new \RuntimeException( 'There are too many open requests right now. Try again after some are answered.' ); }
			$all[] = $request;
			return $all;
		} );
		Fleet::audit( 'request', $site, array( $key ) );
		Fleet_Alerts::request_new( $request );
		return $request;
	}

	public static function decide( string $id, bool $approve, string $reason = '' ): array {
		if ( ! Fleet::can( 'values' ) ) { throw new \RuntimeException( 'Permission to edit site values is required.' ); }
		$request = null;
		foreach ( self::all() as $r ) { if ( $r['id'] === $id ) { $request = $r; } }
		if ( ! $request || 'pending' !== $request['status'] ) { throw new \RuntimeException( 'This request was already answered or replaced. Reload the page.' ); }
		if ( $approve ) { Fleet::via( 'request', static fn() => Fleet::save_profile( (int) $request['site'], array( $request['key'] => $request['value'] ) ) ); }
		$request = array( 'status' => $approve ? 'approved' : 'declined', 'decided_by' => get_current_user_id(), 'decided_at' => gmdate( 'c' ), 'reason' => mb_substr( sanitize_textarea_field( $reason ), 0, 500 ) ) + $request;
		self::write( static function ( array $all ) use ( $request ) {
			foreach ( $all as $i => $r ) { if ( $r['id'] === $request['id'] ) { $all[ $i ] = $request; } }
			return $all;
		} );
		Fleet::audit( 'request-' . $request['status'], (int) $request['site'], array( $request['key'] ) );
		Fleet_Alerts::request_decided( $request );
		return $request;
	}

	/** Keep every open request and the newest 100 answered ones. */
	private static function write( callable $change ): void {
		Fleet::locked( 'requests', static function () use ( $change ) {
			$all = $change( self::all() );
			$open = array_filter( $all, static fn( $r ) => 'pending' === $r['status'] );
			$done = array_slice( array_filter( $all, static fn( $r ) => 'pending' !== $r['status'] ), -self::KEEP_DONE, null, true );
			$keep = $open + $done; ksort( $keep );
			update_network_option( get_current_network_id(), self::OPTION, array_values( $keep ) );
		}, 20 );
	}
}
