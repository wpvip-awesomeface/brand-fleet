<?php
/**
 * Values export: every fleet site's values as a CSV that opens in any spreadsheet and
 * imports straight back (Import tab), so a spreadsheet can stay the working copy.
 */
namespace BrandFleet;

defined( 'ABSPATH' ) || exit;
class Fleet_Export {
	const MODES = array( 'own' => 'Each site\'s own values (empty = uses the shared value). Best for editing and importing back.', 'shown' => 'What each site shows, including shared values. Best for checking.' );

	/** Columns: site_id, site address, then per-site variables in group order. */
	public static function keys(): array {
		return array_keys( array_filter( Fleet::definitions(), static fn( $d ) => 'network' !== $d['scope'] && 'embed' !== $d['type'] ) );
	}

	/** CSV text for the given sites; $emit, when set, receives it row by row instead. */
	public static function csv( array $ids, string $mode = 'own', ?callable $emit = null ): string {
		if ( ! Fleet::can( 'bulk' ) && ! Fleet::can( 'values' ) ) { throw new \RuntimeException( 'Fleet permission required.' ); }
		$keys = self::keys(); $out = '';
		$write = static function ( array $cells ) use ( &$out, $emit ) { $line = implode( ',', array_map( array( self::class, 'cell' ), $cells ) ) . "\r\n"; if ( $emit ) { $emit( $line ); } else { $out .= $line; } };
		$write( array_merge( array( 'site_id', 'domain' ), $keys ) );
		foreach ( $ids as $id ) {
			$id = (int) $id; $p = Fleet::profile( $id );
			if ( ! $p || ! Fleet::site( $id ) ) { continue; }
			$site = get_site( $id ); $row = array( (string) $id, $site->domain . untrailingslashit( $site->path ) );
			foreach ( $keys as $key ) { $row[] = 'shown' === $mode ? (string) Fleet::resolved( $id, $key )['value'] : (string) ( $p['values'][ $key ] ?? '' ); }
			$write( $row );
		}
		return $out;
	}

	/** RFC 4180 quoting, and a leading apostrophe on anything a spreadsheet would run as a formula. */
	public static function cell( string $value ): string {
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) && ! preg_match( '/^[+-]?[\d\s().]+$/', $value ) ) { $value = "'" . $value; }
		return preg_match( '/[",\r\n]/', $value ) ? '"' . str_replace( '"', '""', $value ) . '"' : $value;
	}

	/** Admin download: streams the file so large fleets never build it in memory. */
	public static function download( array $ids, string $mode ): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="brand-fleet-values-' . gmdate( 'Y-m-d' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo "\xEF\xBB\xBF"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- UTF-8 byte order mark so spreadsheets read accents correctly.
		self::csv( $ids, $mode, static function ( string $line ) { echo $line; } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download, not HTML; cells are quoted and formula-guarded by cell().
		Fleet::audit( 'export-values', 0, array() );
	}
}
