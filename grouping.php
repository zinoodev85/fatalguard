<?php
/**
 * Group repeated log entries into distinct errors with a count and
 * first/last-seen — so the error that matters isn't buried under its own
 * repeats. Pure functions (no I/O); consume tail() lines or read_fatals()
 * records.
 *
 * @package FatalGuard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Split a debug.log line into [epoch|null, message]. WP writes
 * "[20-Jul-2026 12:34:56 UTC] <message>"; a line with no leading bracket has no
 * parseable time.
 *
 * @return array{0:?int,1:string}
 */
function fatalguard_split_line( string $line ): array {
	if ( preg_match( '/^\[([^\]]+)\]\s?(.*)$/s', $line, $m ) ) {
		$ts = strtotime( $m[1] );
		return array( false === $ts ? null : $ts, $m[2] );
	}
	return array( null, $line );
}

/**
 * Normalize a message body to a grouping signature: collapse volatile bits (hex
 * pointers, multi-digit line numbers / ids) so the same error at different lines
 * groups together, without over-merging distinct errors.
 */
function fatalguard_signature( string $message ): string {
	$s = trim( $message );
	$s = preg_replace( '/0x[0-9a-fA-F]+/', '0xX', $s ); // pointers
	$s = preg_replace( '/\b\d{2,}\b/', 'N', $s );       // line numbers / ids
	$s = preg_replace( '/\s+/', ' ', $s );
	return ( null === $s || '' === $s ) ? trim( $message ) : $s;
}

/** Max continuation lines (stack trace) kept on one entry. */
const FATALGUARD_DL_MAX_TRACE_LINES = 20;

/**
 * True for an untimed line that continues the entry above it: PHP writes an
 * uncaught exception as one timestamped line followed by "Stack trace:",
 * "#0 …" frames, "  thrown in …" and "Next …" lines with no timestamp.
 */
function fatalguard_is_continuation( string $line ): bool {
	return (bool) preg_match( '/^(?:Stack trace:|#\d+ |Next |\s+\S)/', $line );
}

/**
 * Join stack-trace lines onto the timestamped entry they belong to, so a
 * trace groups with its error instead of each frame becoming its own "error".
 * Continuation lines with no entry above them (the tail started mid-trace) are
 * dropped; untimed lines that are not trace lines stay entries of their own.
 *
 * @param string[] $lines oldest→newest.
 * @return string[] entries, oldest→newest (multi-line entries joined by "\n").
 */
function fatalguard_join_entries( array $lines ): array {
	$entries = array();
	$cur     = null; // index of the last timestamped entry
	$extra   = 0;
	foreach ( $lines as $line ) {
		$line = rtrim( (string) $line, "\r\n" );
		if ( '' === trim( $line ) ) {
			continue;
		}
		$timed = (bool) preg_match( '/^\[[^\]]+\]/', $line );
		if ( ! $timed && fatalguard_is_continuation( $line ) ) {
			if ( null !== $cur && $extra < FATALGUARD_DL_MAX_TRACE_LINES ) {
				$entries[ $cur ] .= "\n" . $line;
				$extra++;
			}
			continue;
		}
		$entries[] = $line;
		$cur       = $timed ? count( $entries ) - 1 : null;
		$extra     = 0;
	}
	return $entries;
}

/**
 * Group raw log lines. Returns entries newest-first:
 * { signature, sample, count, first_seen, last_seen }  (seen = epoch|null).
 * A stack trace stays with its error: the signature comes from the first line,
 * the sample carries the trace.
 *
 * @param string[] $lines oldest→newest, as fatalguard_tail() returns.
 * @return array<int,array>
 */
function fatalguard_group_lines( array $lines ): array {
	$groups = array();
	$order  = 0;
	foreach ( fatalguard_join_entries( $lines ) as $line ) {
		$head              = strtok( $line, "\n" );
		list( $ts, $msg ) = fatalguard_split_line( false === $head ? $line : $head );
		$sig               = fatalguard_signature( $msg );
		if ( '' === $sig ) {
			continue;
		}
		if ( ! isset( $groups[ $sig ] ) ) {
			$groups[ $sig ] = array(
				'signature'  => $sig,
				'sample'     => $line,
				'count'      => 0,
				'first_seen' => $ts,
				'last_seen'  => $ts,
				'_order'     => $order,
			);
		}
		$g = &$groups[ $sig ];
		$g['count']++;
		$g['sample'] = $line; // keep the most recent raw sample
		if ( null !== $ts ) {
			if ( null === $g['first_seen'] || $ts < $g['first_seen'] ) {
				$g['first_seen'] = $ts;
			}
			if ( null === $g['last_seen'] || $ts > $g['last_seen'] ) {
				$g['last_seen'] = $ts;
			}
		}
		$g['_order'] = $order++; // last position wins → recency for untimed lines
		unset( $g );
	}

	$out = array_values( $groups );
	usort( $out, 'fatalguard_group_sort' );
	foreach ( $out as &$g ) {
		unset( $g['_order'] );
	}
	unset( $g );
	return $out;
}

/** Sort grouped entries newest-first: by last_seen, then by insertion recency. */
function fatalguard_group_sort( array $a, array $b ): int {
	$at = $a['last_seen'] ?? null;
	$bt = $b['last_seen'] ?? null;
	if ( null !== $at && null !== $bt && $at !== $bt ) {
		return $bt <=> $at;
	}
	return ( $b['_order'] ?? 0 ) <=> ( $a['_order'] ?? 0 );
}

/**
 * Group captured fatals (NDJSON records) by type|file|line|signature, adding
 * count + first/last-seen. Returns newest-first.
 *
 * @param array<int,array> $fatals as fatalguard_read_fatals() returns.
 * @return array<int,array>
 */
function fatalguard_group_fatals( array $fatals ): array {
	$groups = array();
	foreach ( $fatals as $rec ) {
		if ( ! is_array( $rec ) ) {
			continue;
		}
		$sig = fatalguard_signature( (string) ( $rec['message'] ?? '' ) );
		$key = ( $rec['type'] ?? 0 ) . '|' . ( $rec['file'] ?? '' ) . '|' . ( $rec['line'] ?? 0 ) . '|' . $sig;
		$ts  = isset( $rec['time'] ) ? strtotime( (string) $rec['time'] ) : false;
		$ts  = false === $ts ? null : $ts;

		if ( ! isset( $groups[ $key ] ) ) {
			$rec['count']      = 0;
			$rec['first_seen'] = $ts;
			$rec['last_seen']  = $ts;
			$groups[ $key ]    = $rec;
		}
		$g = &$groups[ $key ];
		$g['count']++;
		if ( null !== $ts ) {
			if ( null === $g['first_seen'] || $ts < $g['first_seen'] ) {
				$g['first_seen'] = $ts;
			}
			if ( null === $g['last_seen'] || $ts > $g['last_seen'] ) {
				$g['last_seen'] = $ts;
			}
		}
		unset( $g );
	}
	$out = array_values( $groups );
	usort(
		$out,
		static function ( array $a, array $b ): int {
			return ( $b['last_seen'] ?? 0 ) <=> ( $a['last_seen'] ?? 0 );
		}
	);
	return $out;
}
