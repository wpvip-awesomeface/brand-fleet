<?php
/**
 * Opening hours: a variable type people can type the way they write hours on a door
 * ("Mon-Fri 9-5, Sat 10:00-14:00, Sun closed"), stored in one canonical form, plus
 * network holiday closures (optionally per segment) that every site's hours respect.
 *
 * Output never varies by visitor: "open now" is worked out in the browser from the
 * next week of opening times, which the server renders per site.
 */
namespace BrandFleet;

defined( 'ABSPATH' ) || exit;
class Fleet_Hours {
	const DAYS = array( 'mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday' );
	/** Network option: holiday closures, each { date, hours, label, segment }. */
	const CLOSURES = 'brand_fleet_closures';
	const MAX_CLOSURES = 100;
	const EXAMPLE = "Mon-Fri 9:00-17:00\nSat 10:00-14:00\nSun closed\n2026-12-25 closed";

	/** Typed hours → canonical text, for example "Mon 09:00-17:00; …; Sun closed; 2026-12-25 closed". */
	public static function clean( string $value ): string {
		$value = trim( $value );
		return '' === $value ? '' : self::format( self::parse( $value ) );
	}

	/** Canonical or typed text → { week: day => [ [open, close] … ] | null, dates: Y-m-d => [ … ] }. Throws with a line-specific message. */
	public static function parse( string $value ): array {
		$week = array(); $dates = array();
		$entries = array_values( array_filter( array_map( 'trim', preg_split( '/[;\n\r]+/', $value ) ), 'strlen' ) );
		if ( count( $entries ) > 60 ) { throw new \InvalidArgumentException( 'Hours can have up to 60 lines.' ); }
		foreach ( $entries as $n => $entry ) {
			$fail = static fn( string $why ) => new \InvalidArgumentException( sprintf( 'Hours line %d (“%s”): %s For example: Mon-Fri 9:00-17:00, or Sun closed, or 2026-12-25 closed.', $n + 1, mb_substr( $entry, 0, 40 ), $why ) );
			if ( preg_match( '/^(\d{4}-\d{2}-\d{2})\s*:?\s*(.+)$/u', $entry, $m ) ) {
				$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $m[1] );
				if ( ! $date || $date->format( 'Y-m-d' ) !== $m[1] ) { throw $fail( 'that is not a real date.' ); }
				$dates[ $m[1] ] = self::ranges( $m[2], $fail );
				continue;
			}
			if ( ! preg_match( '/^([a-z.\s,&–—\-]+?)\s*:?\s*((?:\d|closed|open|24).*)$/iu', $entry, $m ) ) { throw $fail( 'start with a day or a date, then the times.' ); }
			$ranges = self::ranges( $m[2], $fail );
			foreach ( self::days( $m[1], $fail ) as $day ) { $week[ $day ] = $ranges; }
		}
		// Days a weekly schedule does not mention are closed.
		if ( $week ) { foreach ( array_keys( self::DAYS ) as $day ) { $week[ $day ] = $week[ $day ] ?? array(); } }
		ksort( $dates );
		return array( 'week' => $week ? array_merge( array_fill_keys( array_keys( self::DAYS ), array() ), $week ) : null, 'dates' => $dates );
	}

	/** "Mon-Fri", "Mon, Wed & Fri", "weekdays" → day keys. */
	private static function days( string $spec, callable $fail ): array {
		$spec = strtolower( trim( str_replace( array( '–', '—', ' to ', '.' ), array( '-', '-', '-', '' ), $spec ) ) );
		$named = array( 'weekdays' => array( 'mon', 'tue', 'wed', 'thu', 'fri' ), 'weekends' => array( 'sat', 'sun' ), 'weekend' => array( 'sat', 'sun' ), 'daily' => array_keys( self::DAYS ), 'everyday' => array_keys( self::DAYS ) );
		$keys = array_keys( self::DAYS ); $out = array();
		foreach ( preg_split( '/\s*[,&]\s*|\s+and\s+/', $spec ) as $part ) {
			$part = str_replace( ' ', '', $part );
			if ( isset( $named[ $part ] ) ) { $out = array_merge( $out, $named[ $part ] ); continue; }
			$ends = explode( '-', $part );
			if ( count( $ends ) > 2 ) { throw $fail( 'use one dash for a range of days.' ); }
			$from = self::day( $ends[0] ); $to = self::day( $ends[1] ?? $ends[0] );
			if ( null === $from || null === $to ) { throw $fail( 'use day names like Mon, Tue or Monday.' ); }
			for ( $i = $from; ; $i = ( $i + 1 ) % 7 ) { $out[] = $keys[ $i ]; if ( $i === $to ) { break; } }
		}
		return array_values( array_unique( $out ) );
	}
	private static function day( string $name ): ?int {
		foreach ( array_keys( self::DAYS ) as $i => $key ) { if ( strlen( $name ) >= 2 && str_starts_with( strtolower( self::DAYS[ $key ] ), $name ) ) { return $i; } }
		return null;
	}

	/** "9-5", "9am-12pm, 1-5:30pm", "closed", "24 hours" → [ [ 'HH:MM', 'HH:MM' ] … ]; [] means closed. */
	private static function ranges( string $spec, callable $fail ): array {
		$spec = strtolower( trim( str_replace( array( '–', '—', ' to ' ), '-', $spec ) ) );
		if ( preg_match( '/^(closed|close|off)$/', $spec ) ) { return array(); }
		if ( preg_match( '/^(open\s*)?24(\s*h(ours|rs)?|\/7)?$/', $spec ) ) { return array( array( '00:00', '24:00' ) ); }
		$out = array();
		foreach ( preg_split( '/\s*[,&]\s*|\s+and\s+/', $spec ) as $range ) {
			if ( ! preg_match( '/^(\d{1,2})(?:[:.h](\d{2}))?\s*([ap])?\.?m?\.?\s*-\s*(\d{1,2})(?:[:.h](\d{2}))?\s*([ap])?\.?m?\.?$/', $range, $m ) ) { throw $fail( 'write times like 9:00-17:00 or 9am-5pm.' ); }
			$open = self::minutes( (int) $m[1], (int) ( $m[2] ?? 0 ), $m[3] ?? '' ); $close = self::minutes( (int) $m[4], (int) ( $m[5] ?? 0 ), $m[6] ?? '' );
			if ( null === $open || null === $close ) { throw $fail( 'that time does not exist.' ); }
			// "9-5" with no am / pm means 9:00 to 17:00; "1-5pm" means 13:00 to 17:00.
			if ( $close <= $open && '' === ( $m[6] ?? '' ) && $close < 720 ) { $close += 720; }
			if ( '' === ( $m[3] ?? '' ) && 'p' === ( $m[6] ?? '' ) && $open < 720 && $open + 720 < $close ) { $open += 720; }
			if ( 0 === $close && $open > 0 ) { $close = 1440; }
			if ( $close <= $open ) { throw $fail( 'closing time must be after opening time.' ); }
			$out[] = array( self::clock( $open ), self::clock( $close ) );
		}
		usort( $out, static fn( $a, $b ) => strcmp( $a[0], $b[0] ) );
		for ( $i = 1; $i < count( $out ); ++$i ) { if ( $out[ $i ][0] < $out[ $i - 1 ][1] ) { throw $fail( 'times overlap.' ); } }
		return $out;
	}
	private static function minutes( int $hour, int $minute, string $half ): ?int {
		if ( $minute > 59 ) { return null; }
		if ( $half ) {
			if ( $hour < 1 || $hour > 12 ) { return null; }
			$hour = ( $hour % 12 ) + ( 'p' === $half ? 12 : 0 );
		} elseif ( $hour > 24 || ( 24 === $hour && $minute ) ) { return null; }
		return $hour * 60 + $minute;
	}
	private static function clock( int $minutes ): string { return sprintf( '%02d:%02d', intdiv( $minutes, 60 ), $minutes % 60 ); }

	/** Canonical hours as people type them, one line per group of days: "Mon-Fri 09:00-17:00". */
	public static function editable( string $value ): string {
		try { $hours = self::parse( $value ); } catch ( \InvalidArgumentException $e ) { return $value; }
		$lines = array();
		foreach ( self::weekly_rows( $hours ) as $row ) { $lines[] = ucfirst( $row['keys'][0] ) . ( count( $row['keys'] ) > 1 ? '-' . ucfirst( end( $row['keys'] ) ) : '' ) . ' ' . self::times( $hours['week'][ $row['keys'][0] ] ); }
		foreach ( $hours['dates'] as $date => $ranges ) { $lines[] = $date . ' ' . self::times( $ranges ); }
		return implode( "\n", $lines );
	}

	public static function format( array $hours ): string {
		$out = array();
		foreach ( (array) ( $hours['week'] ?? array() ) as $day => $ranges ) { $out[] = ucfirst( $day ) . ' ' . self::times( $ranges ); }
		foreach ( $hours['dates'] ?? array() as $date => $ranges ) { $out[] = $date . ' ' . self::times( $ranges ); }
		return implode( '; ', $out );
	}
	private static function times( array $ranges ): string {
		return $ranges ? implode( ', ', array_map( static fn( $r ) => $r[0] . '-' . $r[1], $ranges ) ) : 'closed';
	}

	/** What {{opening_hours}} prints: "Mon–Fri 9:00 AM–5:00 PM · Sat 10:00 AM–2:00 PM · Sun Closed". */
	public static function display( string $value ): string {
		if ( '' === $value ) { return ''; }
		try { $hours = self::parse( $value ); } catch ( \InvalidArgumentException $e ) { return $value; }
		return implode( ' · ', array_map( static fn( $row ) => $row['days'] . ' ' . $row['times'], self::weekly_rows( $hours ) ) );
	}

	/** Consecutive days with the same times, as display rows { days, times, keys }. */
	public static function weekly_rows( array $hours ): array {
		$rows = array();
		foreach ( (array) ( $hours['week'] ?? array() ) as $day => $ranges ) {
			$last = count( $rows ) - 1;
			if ( $last >= 0 && $rows[ $last ]['ranges'] === $ranges ) { $rows[ $last ]['keys'][] = $day; continue; }
			$rows[] = array( 'keys' => array( $day ), 'ranges' => $ranges );
		}
		return array_map( static function ( $row ) {
			$first = self::abbrev( $row['keys'][0] ); $last = self::abbrev( end( $row['keys'] ) );
			return array( 'keys' => $row['keys'], 'days' => count( $row['keys'] ) > 1 ? $first . '–' . $last : $first, 'times' => self::label( $row['ranges'] ) );
		}, $rows );
	}
	public static function abbrev( string $day ): string {
		global $wp_locale;
		return $wp_locale ? $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( ( array_search( $day, array_keys( self::DAYS ), true ) + 1 ) % 7 ) ) : ucfirst( $day );
	}
	public static function label( array $ranges ): string {
		if ( ! $ranges ) { return __( 'Closed', 'brand-fleet' ); }
		if ( array( array( '00:00', '24:00' ) ) === $ranges ) { return __( 'Open 24 hours', 'brand-fleet' ); }
		return implode( ', ', array_map( static fn( $r ) => self::time( $r[0] ) . '–' . self::time( $r[1] ), $ranges ) );
	}
	/** 'HH:MM' in the site's time format. */
	public static function time( string $clock ): string {
		list( $h, $m ) = array_map( 'intval', explode( ':', $clock ) );
		return wp_date( (string) get_option( 'time_format', 'g:i a' ), ( $h % 24 ) * 3600 + $m * 60, new \DateTimeZone( 'UTC' ) );
	}

	public static function closures(): array {
		return array_values( array_filter( (array) get_network_option( get_current_network_id(), self::CLOSURES, array() ), 'is_array' ) );
	}
	/** Holiday closures form: one per line, "2026-12-25 closed Christmas Day" or "2026-12-24 9-12 Christmas Eve"; segment per line is optional. */
	public static function save_closures( array $rows ): array {
		if ( ! Fleet::can( 'manage' ) ) { throw new \RuntimeException( 'Fleet manager permission required.' ); }
		$out = array(); $segments = Fleet_Segments::all();
		foreach ( $rows as $row ) {
			$date = trim( (string) ( $row['date'] ?? '' ) );
			if ( '' === $date ) { continue; }
			$parsed = self::parse( $date . ' ' . ( trim( (string) ( $row['hours'] ?? '' ) ) ?: 'closed' ) );
			$segment = sanitize_key( (string) ( $row['segment'] ?? '' ) );
			if ( '' !== $segment && ! isset( $segments[ $segment ] ) ) { throw new \InvalidArgumentException( 'Choose an existing segment for each closure.' ); }
			$out[] = array( 'date' => $date, 'hours' => self::times( reset( $parsed['dates'] ) ), 'label' => mb_substr( sanitize_text_field( (string) ( $row['label'] ?? '' ) ), 0, 80 ), 'segment' => $segment );
		}
		if ( count( $out ) > self::MAX_CLOSURES ) { throw new \InvalidArgumentException( 'Up to 100 holiday closures.' ); }
		usort( $out, static fn( $a, $b ) => strcmp( $a['date'], $b['date'] ) );
		update_network_option( get_current_network_id(), self::CLOSURES, $out );
		Fleet::audit( 'closures', 0, array() );
		Fleet_Cache::schedule();
		return $out;
	}

	/** One site's hours for a date: its own date line, then a network closure for its segments, then its weekly hours. Returns [ ranges, label ]. */
	public static function on( int $site, array $hours, string $date ): array {
		if ( isset( $hours['dates'][ $date ] ) ) { return array( $hours['dates'][ $date ], '' ); }
		foreach ( self::closures() as $closure ) {
			if ( $closure['date'] !== $date || ( '' !== $closure['segment'] && ! Fleet_Segments::matches( $site, $closure['segment'] ) ) ) { continue; }
			$parsed = self::parse( $date . ' ' . $closure['hours'] );
			return array( $parsed['dates'][ $date ], $closure['label'] );
		}
		if ( null === $hours['week'] ) { return array( null, '' ); }
		return array( $hours['week'][ strtolower( gmdate( 'D', (int) strtotime( $date . ' 12:00 UTC' ) ) ) ] ?? array(), '' );
	}

	/** The next $days days for one site: { date, label, ranges, note } with the site's timezone. */
	public static function upcoming( int $site, array $hours, int $days = 7 ): array {
		$zone = wp_timezone(); $today = new \DateTimeImmutable( 'today', $zone ); $out = array();
		for ( $i = 0; $i < $days; ++$i ) {
			$day = $today->modify( '+' . $i . ' days' );
			list( $ranges, $note ) = self::on( $site, $hours, $day->format( 'Y-m-d' ) );
			if ( null === $ranges ) { continue; }
			$out[] = array( 'date' => $day->format( 'Y-m-d' ), 'day' => $day, 'ranges' => $ranges, 'note' => $note );
		}
		return $out;
	}

	/** Absolute opening intervals for the browser's "open now" line: [ [ start, end, closes label ] … ] in Unix seconds. */
	public static function intervals( array $upcoming ): array {
		$zone = wp_timezone(); $out = array();
		foreach ( $upcoming as $row ) {
			foreach ( $row['ranges'] as $r ) {
				$start = new \DateTimeImmutable( $row['date'] . ' ' . $r[0], $zone );
				$end = '24:00' === $r[1] ? $start->setTime( 0, 0 )->modify( '+1 day' ) : new \DateTimeImmutable( $row['date'] . ' ' . $r[1], $zone );
				$out[] = array( $start->getTimestamp(), $end->getTimestamp(), self::time( $r[1] ), self::abbrev( strtolower( $start->format( 'D' ) ) ) . ' ' . self::time( $r[0] ) );
			}
		}
		// Back-to-back ranges (24 hours on consecutive days) read as one stretch.
		for ( $i = count( $out ) - 1; $i > 0; --$i ) { if ( $out[ $i - 1 ][1] === $out[ $i ][0] ) { $out[ $i - 1 ][1] = $out[ $i ][1]; $out[ $i - 1 ][2] = $out[ $i ][2]; array_splice( $out, $i, 1 ); } }
		return $out;
	}

	/** schema.org openingHoursSpecification: weekly rows plus closures and special hours in the next 60 days. */
	public static function specification( int $site, array $hours ): array {
		$out = array();
		foreach ( self::weekly_rows( $hours ) as $row ) {
			foreach ( ( $hours['week'][ $row['keys'][0] ] ?? array() ) as $r ) {
				$out[] = array( '@type' => 'OpeningHoursSpecification', 'dayOfWeek' => array_map( static fn( $day ) => self::DAYS[ $day ], $row['keys'] ), 'opens' => $r[0], 'closes' => '24:00' === $r[1] ? '23:59' : $r[1] );
			}
		}
		foreach ( self::upcoming( $site, $hours, 60 ) as $row ) {
			$weekly = null === $hours['week'] ? null : ( $hours['week'][ strtolower( $row['day']->format( 'D' ) ) ] ?? array() );
			if ( $row['ranges'] === $weekly ) { continue; }
			$base = array( '@type' => 'OpeningHoursSpecification', 'validFrom' => $row['date'], 'validThrough' => $row['date'] );
			if ( ! $row['ranges'] ) { $out[] = $base + array( 'opens' => '00:00', 'closes' => '00:00' ); continue; }
			foreach ( $row['ranges'] as $r ) { $out[] = $base + array( 'opens' => $r[0], 'closes' => '24:00' === $r[1] ? '23:59' : $r[1] ); }
		}
		return $out;
	}
}
