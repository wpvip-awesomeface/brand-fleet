<?php
/**
 * Value history: every change to a site's own values, kept on that site (newest 100),
 * so anyone who can edit a value can see what it used to be and put it back.
 */
namespace BrandFleet;

defined( 'ABSPATH' ) || exit;
class Fleet_History {
	const OPTION = 'brand_fleet_history';
	const KEEP = 100;
	/** Longer values are kept shortened for reading, and cannot be restored from history. */
	const MAX_VALUE = 1000;
	const VIA = array( 'edit' => 'Edited', 'bulk' => 'Bulk update', 'import' => 'Import', 'undo' => 'Undo', 'request' => 'Approved request', 'restore' => 'Restored from history' );

	/** Compare a site's own values before and after a save; null means "uses the shared value". */
	public static function record( int $site, array $before, array $after ): void {
		$entries = array(); $time = gmdate( 'c' ); $user = get_current_user_id(); $via = Fleet::current_via();
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $key ) {
			$old = $before[ $key ] ?? null; $new = $after[ $key ] ?? null;
			if ( $old === $new ) { continue; }
			$entries[] = array( 'time' => $time, 'user' => $user, 'key' => (string) $key, 'old' => self::short( $old ), 'new' => self::short( $new ), 'via' => $via ) + ( self::long( $old ) ? array( 'long' => true ) : array() );
		}
		if ( ! $entries ) { return; }
		$log = array_slice( array_merge( self::get( $site ), $entries ), -self::KEEP );
		// Not autoloaded: history is read only on admin screens.
		$switched = get_current_blog_id() !== $site;
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- One option write on the site being saved; restored right after.
		if ( $switched ) { switch_to_blog( $site ); }
		try { update_option( self::OPTION, $log, false ); } finally { if ( $switched ) { restore_current_blog(); } }
	}
	private static function short( ?string $value ): ?string { return null === $value ? null : mb_substr( $value, 0, self::MAX_VALUE ); }
	private static function long( ?string $value ): bool { return null !== $value && mb_strlen( $value ) > self::MAX_VALUE; }

	public static function get( int $site ): array {
		return array_values( array_filter( (array) get_blog_option( $site, self::OPTION, array() ), 'is_array' ) );
	}

	/** Put one variable back to what it was before entry $index. Permissions are checked by the profile save. */
	public static function restore( int $site, int $index, string $expected ): void {
		$entry = self::get( $site )[ $index ] ?? null;
		if ( ! $entry || ! hash_equals( Fleet::hash( $entry ), $expected ) ) { throw new \RuntimeException( 'History changed; reload and try again.' ); }
		if ( ! empty( $entry['long'] ) ) { throw new \InvalidArgumentException( 'That value was too long to keep in full, so it cannot be restored from history.' ); }
		$defs = Fleet::definitions();
		if ( ! isset( $defs[ $entry['key'] ] ) ) { throw new \InvalidArgumentException( 'That variable no longer exists.' ); }
		// Imported values are only ever written by people who could run the import.
		if ( 'imported' === $defs[ $entry['key'] ]['scope'] && ! Fleet::can( 'values' ) && ! Fleet::can( 'bulk' ) ) { throw new \RuntimeException( 'This value comes from an import; a fleet editor can restore it.' ); }
		$patch = array( $entry['key'] => $entry['old'] );
		$write = static fn() => Fleet::via( 'restore', static fn() => Fleet::save_profile( $site, $patch ) );
		'imported' === $defs[ $entry['key'] ]['scope'] ? Fleet::import_write( $write ) : $write();
	}
}
