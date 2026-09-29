<?php
/** Export/import of variable definitions between networks; never touches per-site values. */
namespace BrandFleet;

defined( 'ABSPATH' ) || exit;

class Fleet_Transfer {
	const FORMAT_VERSION = 1;
	const DIFF_FIELDS = array( 'label', 'type', 'default', 'scope', 'access', 'required', 'clone' );

	/** Definitions only: no site data crosses networks, and site IDs from one network are meaningless on another. */
	public static function export(): array {
		$definitions = array();
		foreach ( Fleet::definitions() as $key => $d ) {
			unset( $d['sites'] );
			if ( 'selected' === $d['access'] ) { $d['access'] = 'none'; }
			$definitions[ $key ] = $d;
		}
		return array(
			'format_version' => self::FORMAT_VERSION,
			'plugin_version' => BRAND_FLEET_VERSION,
			'exported_at'    => gmdate( 'c' ),
			'definitions'    => $definitions,
		);
	}

	/** Shared parsing for the paste/upload UI inputs and any raw text an ability caller supplies. */
	public static function decode( string $raw ): array {
		if ( strlen( $raw ) > 1_048_576 ) { throw new \InvalidArgumentException( 'File is larger than the 1 MB limit.' ); }
		try {
			$document = json_decode( $raw, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $e ) {
			throw new \InvalidArgumentException( "That doesn't look like valid JSON. Check the file and try again." );
		}
		if ( ! is_array( $document ) || ! isset( $document['format_version'] ) || self::FORMAT_VERSION !== $document['format_version'] ) {
			throw new \InvalidArgumentException( 'This file uses a newer or unrecognized export format.' );
		}
		if ( ! isset( $document['definitions'] ) || ! is_array( $document['definitions'] ) ) {
			throw new \InvalidArgumentException( "This doesn't look like a Brand Fleet export file." );
		}
		return $document;
	}

	/**
	 * Walks the document in file order against a running copy of the current definitions, so the
	 * 200-definition cap and type-immutability rules see prior accepted rows exactly as save_definition()
	 * would if called once per key in sequence. Never trusted across a request boundary: both preview
	 * and apply call this fresh from Fleet::definitions().
	 */
	private static function classify( array $document ): array {
		$defs = Fleet::definitions();
		$rows = array();
		$accepted = array();
		foreach ( (array) ( $document['definitions'] ?? array() ) as $key => $input ) {
			$key = (string) $key;
			$input = is_array( $input ) ? $input : array();
			$existing = $defs[ $key ] ?? null;
			$kept_delegation = false;
			// Export always downgrades access:selected to none; re-importing onto the same key must not silently drop delegation.
			if ( $existing && 'selected' === $existing['access'] && 'none' === ( $input['access'] ?? 'none' ) ) {
				$input['access'] = 'selected';
				$input['sites']  = $existing['sites'];
				$kept_delegation = true;
			}
			try {
				$d = Fleet::validate_definition( $defs, $key, $input );
			} catch ( \Throwable $e ) {
				$rows[ $key ] = array( 'status' => 'rejected', 'label' => (string) ( $input['label'] ?? $key ), 'reason' => $e->getMessage() );
				continue;
			}
			if ( null === $existing ) {
				$rows[ $key ] = array( 'status' => 'new', 'label' => $d['label'] );
				$accepted[ $key ] = $d;
				$defs[ $key ] = $d;
				continue;
			}
			$diff = array();
			foreach ( self::DIFF_FIELDS as $field ) {
				if ( $existing[ $field ] !== $d[ $field ] ) { $diff[ $field ] = array( 'before' => $existing[ $field ], 'after' => $d[ $field ] ); }
			}
			if ( $diff ) {
				$rows[ $key ] = array( 'status' => 'changed', 'label' => $d['label'], 'diff' => $diff );
				$accepted[ $key ] = $d;
				$defs[ $key ] = $d;
			} else {
				$rows[ $key ] = array( 'status' => 'unchanged', 'label' => $d['label'] );
			}
			if ( $kept_delegation ) { $rows[ $key ]['kept_delegation'] = true; }
		}
		return array( 'rows' => $rows, 'accepted' => $accepted );
	}

	public static function preview_import( array $document ): array {
		$classified = self::classify( $document );
		return array( 'rows' => $classified['rows'], 'schema_hash' => Fleet::hash( Fleet::definitions() ) );
	}

	public static function apply_import( array $document, string $expected ): array {
		return Fleet::locked( 'schema', static function () use ( $document, $expected ) {
			if ( ! Fleet::network_admin() ) { throw new \RuntimeException( 'Network administrator permission required.' ); }
			if ( ! hash_equals( $expected, Fleet::hash( Fleet::definitions() ) ) ) { throw new \RuntimeException( 'Definitions changed; reload and preview again.' ); }
			$accepted = self::classify( $document )['accepted'];
			if ( ! $accepted ) { return array(); }
			$defs = Fleet::definitions();
			foreach ( $accepted as $key => $d ) {
				// Import never carries site delegation; keep whatever the target already had for this key.
				$d['sites'] = $defs[ $key ]['sites'] ?? array();
				$defs[ $key ] = $d;
			}
			update_network_option( get_current_network_id(), Fleet::SCHEMA, $defs );
			$keys = array_keys( $accepted );
			Fleet::audit( 'import', 0, $keys );
			Fleet_Cache::schedule();
			return $keys;
		} );
	}
}
