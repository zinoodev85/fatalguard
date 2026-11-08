<?php
/**
 * Read-only data callbacks for AI access: status, log sources, read a log, grouped
 * errors, grouped fatals, one fatal. FatalGuard's own MCP endpoint (overlay
 * mcp/tools.php) calls these; nothing here registers with another plugin or the
 * core Abilities API (user decision 2026-11-05: no reliance on other plugins).
 * Callers own the permission check. Output is scrubbed by the reader and every
 * list is capped. Plan: products/fatalguard/meta/mcp-abilities-plan.md.
 *
 * @package FatalGuard
 */

defined( 'ABSPATH' ) || exit;

const FATALGUARD_DL_ABILITY_MAX_LINES  = 500;
const FATALGUARD_DL_ABILITY_MAX_GROUPS = 100;
const FATALGUARD_DL_ABILITY_SCAN       = 1000; // lines / records read before grouping or filtering

function fatalguard_ability_status(): array {
	$state = fatalguard_state();
	$sizes = array();
	foreach ( fatalguard_sources() as $s ) {
		$sizes[ $s['id'] ] = (int) $s['size'];
	}
	$last = fatalguard_read_fatals( 1 );
	return array(
		'logging_on'          => (bool) $state['log_errors'],
		'display_errors'      => (bool) $state['display_errors'],
		'wp_debug'            => defined( 'WP_DEBUG' ) && WP_DEBUG,
		'wp_debug_log'        => defined( 'WP_DEBUG_LOG' ) && (bool) WP_DEBUG_LOG,
		'wp_debug_display'    => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
		'fatal_capture'       => (bool) $state['fatal_capture'],
		'disabled'            => fatalguard_is_disabled(),
		'telegram_configured' => fatalguard_telegram_configured(),
		'log_sizes'           => $sizes,
		'last_fatal'          => isset( $last[0]['time'] ) ? (string) $last[0]['time'] : null,
	);
}

function fatalguard_ability_sources(): array {
	$keep = array_flip( array( 'id', 'label', 'group', 'kind', 'exists', 'readable', 'size', 'mtime' ) );
	return array_map(
		static function ( array $s ) use ( $keep ): array {
			return array_intersect_key( $s, $keep );
		},
		fatalguard_sources()
	);
}

/** @param mixed $input */
function fatalguard_ability_read_log( $input = null ) {
	$in     = (array) $input;
	$source = fatalguard_ability_source( $in );
	$max    = fatalguard_ability_cap( $in['lines'] ?? 100, FATALGUARD_DL_ABILITY_MAX_LINES );
	$filter = trim( (string) ( $in['filter'] ?? '' ) );
	$lines  = fatalguard_ability_tail( $source, '' === $filter ? $max : FATALGUARD_DL_ABILITY_SCAN );
	if ( is_wp_error( $lines ) ) {
		return $lines;
	}
	$lines = array_values( array_slice( fatalguard_filter_lines( $lines, $filter ), -$max ) );
	return array( 'source' => $source, 'count' => count( $lines ), 'lines' => $lines );
}

/** @param mixed $input */
function fatalguard_ability_list_errors( $input = null ) {
	$in    = (array) $input;
	$lines = fatalguard_ability_tail( fatalguard_ability_source( $in ), FATALGUARD_DL_ABILITY_SCAN );
	if ( is_wp_error( $lines ) ) {
		return $lines;
	}
	$all = fatalguard_group_lines( $lines );
	$out = array();
	foreach ( array_slice( $all, 0, fatalguard_ability_cap( $in['limit'] ?? 50, FATALGUARD_DL_ABILITY_MAX_GROUPS ) ) as $g ) {
		$msg = fatalguard_split_line( (string) $g['sample'] )[1];
		preg_match( '/^PHP ([A-Za-z ]+?):/', $msg, $lvl );
		preg_match( '/ in (\S+?)(?: on line |:)(\d+)/', $msg, $at );
		$out[] = array(
			'signature'  => (string) $g['signature'],
			'level'      => $lvl[1] ?? '',
			'message'    => $msg,
			'file'       => isset( $at[1] ) ? fatalguard_relativize( $at[1] ) : '',
			'line'       => isset( $at[2] ) ? (int) $at[2] : 0,
			'count'      => (int) $g['count'],
			'first_seen' => fatalguard_ability_iso( $g['first_seen'] ),
			'last_seen'  => fatalguard_ability_iso( $g['last_seen'] ),
		);
	}
	return array( 'total' => count( $all ), 'groups' => $out );
}

/** @param mixed $input */
function fatalguard_ability_list_fatals( $input = null ): array {
	$in  = (array) $input;
	$all = fatalguard_group_fatals( fatalguard_read_fatals( FATALGUARD_DL_ABILITY_SCAN ) );
	$out = array();
	foreach ( array_slice( $all, 0, fatalguard_ability_cap( $in['limit'] ?? 20, FATALGUARD_DL_ABILITY_MAX_GROUPS ) ) as $rec ) {
		$out[] = array(
			'signature'  => fatalguard_signature( (string) ( $rec['message'] ?? '' ) ),
			'type'       => (string) ( $rec['type_label'] ?? '' ),
			'message'    => (string) ( $rec['message'] ?? '' ),
			'file'       => (string) ( $rec['file'] ?? '' ),
			'line'       => (int) ( $rec['line'] ?? 0 ),
			'origin'     => (string) ( $rec['origin'] ?? 'unknown' ),
			'count'      => (int) $rec['count'],
			'first_seen' => fatalguard_ability_iso( $rec['first_seen'] ),
			'last_seen'  => fatalguard_ability_iso( $rec['last_seen'] ),
		);
	}
	return array( 'total' => count( $all ), 'groups' => $out );
}

/** @param mixed $input */
function fatalguard_ability_get_fatal( $input = null ) {
	$sig     = trim( (string) ( ( (array) $input )['signature'] ?? '' ) );
	$matches = array();
	foreach ( fatalguard_read_fatals( FATALGUARD_DL_ABILITY_SCAN ) as $rec ) { // newest first
		if ( '' !== $sig && fatalguard_signature( (string) ( $rec['message'] ?? '' ) ) === $sig ) {
			$matches[] = $rec;
		}
	}
	if ( ! $matches ) {
		return new WP_Error( 'fatalguard_dl_fatal_not_found', 'No captured fatal error has this signature. Call list_recent_fatals for current signatures.', array( 'status' => 404 ) );
	}
	$latest = $matches[0];
	$occurs = array();
	foreach ( array_slice( $matches, 0, 10 ) as $rec ) {
		$occurs[] = array( 'time' => (string) ( $rec['time'] ?? '' ), 'url' => fatalguard_scrub( (string) ( $rec['url'] ?? '' ) ) );
	}
	return array(
		'signature'   => $sig,
		'count'       => count( $matches ),
		'record'      => array(
			'time'        => (string) ( $latest['time'] ?? '' ),
			'type'        => (string) ( $latest['type_label'] ?? '' ),
			'message'     => (string) ( $latest['message'] ?? '' ),
			'file'        => (string) ( $latest['file'] ?? '' ),
			'line'        => (int) ( $latest['line'] ?? 0 ),
			'origin'      => (string) ( $latest['origin'] ?? 'unknown' ),
			'url'         => fatalguard_scrub( (string) ( $latest['url'] ?? '' ) ),
			'php_version' => (string) ( $latest['php_version'] ?? '' ),
		),
		'occurrences' => $occurs,
	);
}

/** The requested source id, or the first readable one (sources() lists the PHP error log first). */
function fatalguard_ability_source( array $in ): string {
	$source = trim( (string) ( $in['source'] ?? '' ) );
	if ( '' !== $source ) {
		return $source;
	}
	foreach ( fatalguard_sources() as $s ) {
		if ( ! empty( $s['readable'] ) ) {
			return (string) $s['id'];
		}
	}
	return 'wp-errors';
}

/** Tail an allow-listed source; the reader scrubs every line. @return string[]|WP_Error */
function fatalguard_ability_tail( string $source, int $max ) {
	$path = fatalguard_resolve_source( $source );
	if ( '' === $path ) {
		return new WP_Error( 'fatalguard_dl_unknown_source', 'Unknown or unreadable log source. Call list_log_sources for valid ids.', array( 'status' => 404 ) );
	}
	return fatalguard_tail( $path, $max );
}

/** @param mixed $value */
function fatalguard_ability_cap( $value, int $max ): int {
	return max( 1, min( $max, (int) $value ) );
}

/** @param mixed $ts Epoch seconds or null. */
function fatalguard_ability_iso( $ts ): ?string {
	return is_int( $ts ) ? gmdate( 'c', $ts ) : null;
}
