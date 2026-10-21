<?php
/**
 * Email alerts for things that need a person: a daily import that failed, a batch that
 * stopped part-way, and change requests from sites. Each alert repeats at most once an
 * hour, so a broken spreadsheet link cannot flood an inbox.
 */
namespace BrandFleet;

defined( 'ABSPATH' ) || exit;
class Fleet_Alerts {
	const OPTION = 'brand_fleet_alerts';
	const EVENTS = array( 'import_failed' => 'A daily import fails', 'batch_stopped' => 'A bulk update, import, find & replace or undo stops before it finishes', 'request_new' => 'A site asks for a change to a value it cannot edit', 'request_decided' => 'Tell the person who asked when their request is approved or declined' );
	const MAX_EMAILS = 10;

	public static function settings(): array {
		$saved = (array) get_network_option( get_current_network_id(), self::OPTION, array() );
		return $saved + array( 'emails' => array_filter( array( (string) get_site_option( 'admin_email' ) ) ), 'events' => array_keys( self::EVENTS ) );
	}
	public static function save( string $emails, array $events ): array {
		if ( ! Fleet::network_admin() ) { throw new \RuntimeException( 'Network administrator permission required.' ); }
		$list = array_values( array_unique( array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', $emails ) ) ) ) );
		foreach ( $list as $email ) { if ( ! is_email( $email ) ) { throw new \InvalidArgumentException( sprintf( '“%s” is not an email address.', $email ) ); } } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped by the UI handler.
		if ( count( $list ) > self::MAX_EMAILS ) { throw new \InvalidArgumentException( 'Up to 10 email addresses.' ); }
		$settings = array( 'emails' => $list, 'events' => array_values( array_intersect( array_map( 'sanitize_key', $events ), array_keys( self::EVENTS ) ) ) );
		update_network_option( get_current_network_id(), self::OPTION, $settings );
		Fleet::audit( 'alerts', 0, array() );
		return $settings;
	}
	public static function on( string $event ): bool { return in_array( $event, (array) self::settings()['events'], true ); }

	/** Send one alert to the alert list plus $also, unless the same alert went out in the last hour. */
	private static function send( string $event, string $subject, array $lines, array $also = array(), bool $list = true ): bool {
		if ( ! self::on( $event ) ) { return false; }
		$to = array_values( array_unique( array_filter( array_merge( $list ? (array) self::settings()['emails'] : array(), $also ), 'is_email' ) ) );
		$throttle = 'brand_fleet_alert_' . md5( $event . $subject . implode( ',', $to ) );
		if ( ! $to || get_site_transient( $throttle ) ) { return false; }
		set_site_transient( $throttle, 1, HOUR_IN_SECONDS );
		$lines[] = ''; $lines[] = 'Open Brand Fleet: ' . network_admin_url( 'admin.php?page=brand-fleet' );
		return (bool) wp_mail( $to, '[Brand Fleet] ' . $subject, implode( "\n", $lines ) ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- A few admin alerts, at most one per alert per hour; never bulk mail.
	}
	private static function email( int $user ): string { $u = get_userdata( $user ); return $u ? (string) $u->user_email : ''; }

	public static function import_failed( string $error, int $owner ): void {
		self::send( 'import_failed', 'The daily import did not run', array( 'The daily spreadsheet import for ' . get_network()->site_name . ' did not run:', '', $error, '', 'Fix it under Brand Fleet → Import. The next run is tomorrow.' ), array( self::email( $owner ) ) );
	}
	public static function batch_stopped( array $j ): void {
		$counts = Fleet_Jobs::counts( $j );
		self::send( 'batch_stopped', Fleet_Undo::label( $j ) . ' stopped', array( Fleet_Undo::label( $j ) . ' stopped after ' . (int) $j['cursor'] . ' of ' . count( $j['ids'] ) . ' sites.', 'Reason: ' . ( $j['error'] ?? 'unknown' ), sprintf( 'Updated: %d. Skipped: %d.', $counts['updated'] ?? 0, $counts['skipped'] ?? 0 ), '', 'Sites already updated keep their new values. Start a new preview to finish, or undo it from Activity.' ), array( self::email( (int) $j['user'] ) ) );
	}
	public static function request_new( array $request ): void {
		$d = Fleet::definitions()[ $request['key'] ] ?? array( 'label' => $request['key'] );
		self::send( 'request_new', 'Change requested: ' . $d['label'] . ' on ' . Site_Picker::label( (int) $request['site'] ), array( self::name( (int) $request['user'] ) . ' asked to change ' . $d['label'] . ' on ' . Site_Picker::label( (int) $request['site'] ) . ' to:', '', $request['value'], '' !== $request['note'] ? "\nNote: " . $request['note'] : '', '', 'Approve or decline it under Brand Fleet → Requests.' ) );
	}
	public static function request_decided( array $request ): void {
		$d = Fleet::definitions()[ $request['key'] ] ?? array( 'label' => $request['key'] );
		$approved = 'approved' === $request['status'];
		self::send( 'request_decided', 'Your change request was ' . $request['status'], array( 'Your request to change ' . $d['label'] . ' on ' . Site_Picker::label( (int) $request['site'] ) . ' was ' . $request['status'] . ' by ' . self::name( (int) $request['decided_by'] ) . '.', $approved ? 'The site now shows the new value.' : '', '' !== (string) ( $request['reason'] ?? '' ) ? 'Note: ' . $request['reason'] : '' ), array( self::email( (int) $request['user'] ) ), false );
	}
	private static function name( int $user ): string { $u = get_userdata( $user ); return $u ? $u->display_name : 'Someone'; }
}
