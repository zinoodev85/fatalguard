<?php
/**
 * Log discovery + bounded reverse-seek tail. Reads are confined to an exact
 * realpath allowlist (no traversal), symlinks are rejected, only .log/.ndjson/
 * error_log are eligible, and a 256KB byte window bounds memory even for a
 * newline-less multi-MB line. Every line is UTF-8-sanitised and scrubbed.
 *
 * @package FatalGuard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- This is a log reader: it tails the
// debug/error log with direct fopen()/fseek()/fread() handles. WP_Filesystem has no seek/tail
// primitive, so it cannot read only the last N bytes of a large, actively-written log.

const FATALGUARD_DL_BYTE_CAP = 262144; // 256KB tail window

/**
 * Classify a candidate log path. `is_file()` is false for /dev/stderr (a
 * symlink to a pipe) even though `is_readable()` is true — so we probe.
 *
 * @return array{exists:bool,kind:string,readable:bool,writable:bool,size:int,mtime:int}
 */
function fatalguard_classify( string $path ): array {
	$out = array(
		'exists'   => false,
		'kind'     => 'missing',
		'readable' => false,
		'writable' => false,
		'size'     => 0,
		'mtime'    => 0,
	);
	if ( '' === $path || ! @file_exists( $path ) ) {
		return $out;
	}
	$out['exists'] = true;

	if ( @is_link( $path ) || ! @is_file( $path ) ) {
		$out['kind'] = 'stream';
		return $out;
	}

	$out['kind']     = 'file';
	$out['readable'] = @is_readable( $path );
	$out['writable'] = @is_writable( $path );
	$out['size']     = (int) @filesize( $path );
	$out['mtime']    = (int) @filemtime( $path );
	return $out;
}

/**
 * Discover all log sources, grouped. Our managed php-error.log is clearable;
 * everything else is read-only. wp-content/debug.log, if web-fetchable, is
 * flagged.
 *
 * @return array<int,array>
 */
function fatalguard_sources(): array {
	$sources = array();

	// Our managed WordPress/PHP error log.
	$php_log = fatalguard_php_log();
	$sources[] = fatalguard_make_source( 'wp-errors', 'WordPress / PHP errors', 'wordpress', $php_log, true );

	// A native wp-content/debug.log (if a site/host writes one separately).
	$native = WP_CONTENT_DIR . '/debug.log';
	if ( '' !== $native && @is_file( $native ) && realpath( $native ) !== realpath( $php_log ) ) {
		$s              = fatalguard_make_source( 'wp-debug-native', 'wp-content/debug.log', 'wordpress', $native, true );
		$s['warning']   = 'This file sits in wp-content and may be web-fetchable.';
		$sources[]      = $s;
	}

	// PHP ini error_log (often /dev/stderr on wp-env — classified as a stream).
	$ini = (string) @ini_get( 'error_log' );
	if ( '' !== $ini && realpath( $ini ) !== realpath( $php_log ) ) {
		$sources[] = fatalguard_make_source( 'php-ini', 'PHP error_log (php.ini)', 'php', $ini, false );
	}

	// Other fatalguard-*.log files at the wp-content root (e.g. ai-blocks).
	$base = WP_CONTENT_DIR;
	if ( '' !== $base ) {
		$glob = @glob( $base . '/fatalguard-*.log' );
		if ( is_array( $glob ) ) {
			foreach ( $glob as $file ) {
				if ( @is_link( $file ) || ! @is_file( $file ) ) {
					continue;
				}
				$name      = basename( $file );
				$sources[] = fatalguard_make_source( 'fatalguard-' . md5( $file ), $name, 'fatalguard', $file, true );
			}
		}
	}

	// JS error log — in the private hardened dir (not matched by the root glob).
	$js_log = fatalguard_dir() . '/js-error.log';
	if ( @is_file( $js_log ) ) {
		$sources[] = fatalguard_make_source( 'js-errors', 'JS errors (browser)', 'fatalguard', $js_log, true );
	}

	// CSP violation reports (csp-report.php) — same private dir.
	$csp_log = fatalguard_dir() . '/csp-report.log';
	if ( @is_file( $csp_log ) ) {
		$sources[] = fatalguard_make_source( 'csp-reports', 'CSP reports (browser)', 'fatalguard', $csp_log, true );
	}

	// B124: WP_DEBUG_LOG as a custom string path (e.g. outside wp-content).
	// wp_debug_mode() routes errors here between wp-settings load and plugins_loaded;
	// early-boot notices/warnings from other plugins land in this file, not in our
	// managed log. Register it as a read-only source so the viewer shows them.
	if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG ) {
		$wp_debug_log = WP_DEBUG_LOG;
		if ( @is_file( $wp_debug_log ) && realpath( $wp_debug_log ) !== realpath( $php_log ) ) {
			$sources[] = fatalguard_make_source( 'wp-debug-const', 'WP_DEBUG_LOG (custom path)', 'wordpress', $wp_debug_log, false );
		}
	}

	return $sources;
}

/** Build one source descriptor. */
function fatalguard_make_source( string $id, string $label, string $group, string $path, bool $clearable ): array {
	$info = fatalguard_classify( $path );
	return array(
		'id'        => $id,
		'label'     => $label,
		'group'     => $group,
		'path'      => fatalguard_relativize( $path ),
		'kind'      => $info['kind'],
		'exists'    => $info['exists'],
		'readable'  => $info['readable'],
		'size'      => $info['size'],
		'mtime'     => $info['mtime'],
		'clearable' => $clearable && 'file' === $info['kind'],
	);
}

/**
 * The exact-realpath set of files the reader/clearer may touch. Computed from
 * discovery so there is no path-prefix guesswork.
 *
 * @return array<string,array{clearable:bool}>  realpath => meta
 */
function fatalguard_allowlist(): array {
	$allow = array();

	// (path, clearable) PAIRS — deliberately a list, NOT a path-keyed map. The
	// same realpath can legitimately appear twice: fatalguard_apply()
	// points the php.ini error_log at our managed php-error.log, so the
	// read-only ini candidate collides with the clearable managed-log candidate.
	// As a path-keyed array the later (false) entry silently clobbered the
	// earlier (true) one, making our own log un-clearable. We keep them separate
	// and OR the flag per realpath below, so "clearable" is sticky.
	$candidates = array(
		array( fatalguard_php_log(), true ),    // managed WP/PHP log — clearable
		array( fatalguard_fatals_log(), true ), // captured fatals — clearable
		array( fatalguard_dir() . '/js-error.log', true ), // JS error log — clearable
	);
	$base = WP_CONTENT_DIR;
	if ( '' !== $base ) {
		$native = $base . '/debug.log';
		if ( @is_file( $native ) ) {
			$candidates[] = array( $native, true );
		}
		$glob = @glob( $base . '/fatalguard-*.log' );
		if ( is_array( $glob ) ) {
			foreach ( $glob as $file ) {
				$candidates[] = array( $file, true );
			}
		}
	}
	$ini = (string) @ini_get( 'error_log' );
	if ( '' !== $ini ) {
		$candidates[] = array( $ini, false );
	}
	// B124: WP_DEBUG_LOG custom path (read-only — we do not own this file).
	if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG ) {
		$candidates[] = array( WP_DEBUG_LOG, false );
	}

	foreach ( $candidates as $cand ) {
		list( $path, $clearable ) = $cand;
		if ( @is_link( $path ) || ! @is_file( $path ) ) {
			continue;
		}
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'log', 'ndjson' ), true ) && 'error_log' !== basename( $path ) ) {
			continue;
		}
		$real = realpath( $path );
		if ( false === $real ) {
			continue;
		}
		$prev          = isset( $allow[ $real ] ) ? (bool) $allow[ $real ]['clearable'] : false;
		$allow[ $real ] = array( 'clearable' => $prev || (bool) $clearable );
	}
	return $allow;
}

/** Resolve a source id back to a vetted absolute path (or '' if not allowed). */
function fatalguard_resolve_source( string $id ): string {
	$map = array(
		'wp-errors' => fatalguard_php_log(),
	);
	$base = WP_CONTENT_DIR;
	if ( 'wp-debug-native' === $id && '' !== $base ) {
		$map['wp-debug-native'] = $base . '/debug.log';
	}
	if ( 'php-ini' === $id ) {
		$map['php-ini'] = (string) @ini_get( 'error_log' );
	}
	if ( 0 === strpos( $id, 'fatalguard-' ) && '' !== $base ) {
		$glob = @glob( $base . '/fatalguard-*.log' );
		if ( is_array( $glob ) ) {
			foreach ( $glob as $file ) {
				if ( 'fatalguard-' . md5( $file ) === $id ) {
					$map[ $id ] = $file;
				}
			}
		}
	}
	if ( 'js-errors' === $id ) {
		$map['js-errors'] = fatalguard_dir() . '/js-error.log';
	}
	// B124: WP_DEBUG_LOG custom path.
	if ( 'wp-debug-const' === $id && defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) ) {
		$map['wp-debug-const'] = WP_DEBUG_LOG;
	}
	$path = $map[ $id ] ?? '';
	if ( '' === $path ) {
		return '';
	}
	$real  = realpath( $path );
	$allow = fatalguard_allowlist();
	return ( false !== $real && isset( $allow[ $real ] ) ) ? $real : '';
}

/** Sanitise one line: valid UTF-8, strip control chars except tab. */
function fatalguard_sanitize_line( string $line ): string {
	$line = (string) wp_check_invalid_utf8( $line, true );
	$line = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $line );
	return null === $line ? '' : $line;
}

/**
 * Bounded reverse-seek tail. Reads at most 256KB from the end; drops a leading
 * partial line; returns the last $max sanitised + scrubbed lines.
 *
 * @return string[]
 */
function fatalguard_tail( string $path, int $max = 200 ): array {
	$max = max( 1, min( 1000, $max ) );
	if ( '' === $path || @is_link( $path ) || ! @is_file( $path ) || ! @is_readable( $path ) ) {
		return array();
	}
	$fp = @fopen( $path, 'rb' );
	if ( ! $fp ) {
		return array();
	}
	$stat = @fstat( $fp );
	$size = isset( $stat['size'] ) ? (int) $stat['size'] : 0;
	if ( $size <= 0 ) {
		@fclose( $fp );
		return array();
	}
	$read = (int) min( $size, FATALGUARD_DL_BYTE_CAP );
	@fseek( $fp, -$read, SEEK_END );
	$buffer = @fread( $fp, $read );
	@fclose( $fp );
	if ( false === $buffer || '' === $buffer ) {
		return array();
	}

	$truncated = $read < $size;
	if ( $truncated ) {
		$nl = strpos( $buffer, "\n" );
		if ( false !== $nl ) {
			$buffer = substr( $buffer, $nl + 1 );
		}
	}

	$parts = preg_split( '/\r\n|\r|\n/', rtrim( $buffer, "\r\n" ) );
	if ( ! is_array( $parts ) ) {
		return array();
	}
	$parts = array_slice( $parts, -$max );
	$parts = array_map( 'fatalguard_sanitize_line', $parts );
	$parts = fatalguard_scrub_lines( $parts );

	if ( $truncated ) {
		array_unshift( $parts, '…[older lines truncated — showing last ' . size_format( FATALGUARD_DL_BYTE_CAP ) . ']' );
	}
	return $parts;
}

/**
 * Free-text filter over already-read lines. Case-insensitive substring by
 * default; `$regex` opts into a delimiter-escaped, validated pattern (falls back
 * to substring if the pattern is invalid). Operates on the bounded tail window,
 * so it never widens the read.
 *
 * @param string[] $lines
 * @return string[]
 */
function fatalguard_filter_lines( array $lines, string $q, bool $regex = false ): array {
	$q = trim( $q );
	if ( '' === $q ) {
		return $lines;
	}
	$pattern = '';
	if ( $regex ) {
		$pattern = '/' . str_replace( '/', '\/', $q ) . '/i';
		if ( false === @preg_match( $pattern, '' ) ) {
			$regex = false; // invalid regex → treat as substring
		}
	}
	$out = array();
	foreach ( $lines as $line ) {
		$line = (string) $line;
		if ( $regex ) {
			if ( 1 === @preg_match( $pattern, $line ) ) {
				$out[] = $line;
			}
		} elseif ( false !== stripos( $line, $q ) ) {
			$out[] = $line;
		}
	}
	return $out;
}

/** Truncate a clearable log file atomically-ish (open c+, ftruncate). */
function fatalguard_clear( string $path ): bool {
	$real  = realpath( $path );
	$allow = fatalguard_allowlist();
	if ( false === $real || empty( $allow[ $real ]['clearable'] ) ) {
		return false;
	}
	if ( @is_link( $real ) || ! @is_writable( $real ) ) {
		return false;
	}
	$fp = @fopen( $real, 'c+' );
	if ( ! $fp ) {
		return false;
	}
	$ok = @ftruncate( $fp, 0 );
	@fflush( $fp );
	@fclose( $fp );
	clearstatcache( true, $real );
	return (bool) $ok;
}

/**
 * Read captured fatals (newest first) from fatals.ndjson.
 *
 * @return array<int,array>
 */
function fatalguard_read_fatals( int $max = 100 ): array {
	$path = fatalguard_fatals_log();
	if ( ! @is_file( $path ) || ! @is_readable( $path ) ) {
		return array();
	}
	$lines = fatalguard_tail_raw( $path, $max );
	$out   = array();
	foreach ( array_reverse( $lines ) as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		$rec = json_decode( $line, true );
		if ( is_array( $rec ) ) {
			// Defense-in-depth: scrub again on read.
			if ( isset( $rec['message'] ) ) {
				$rec['message'] = fatalguard_scrub( (string) $rec['message'] );
			}
			// B104: backfill origin/type_label for lean drop-in records that lack them.
			if ( empty( $rec['origin'] ) && ! empty( $rec['file'] ) && function_exists( 'fatalguard_classify_source' ) ) {
				$rec['origin'] = fatalguard_classify_source( (string) $rec['file'] );
			}
			if ( empty( $rec['type_label'] ) && function_exists( 'fatalguard_error_type_label' ) ) {
				$rec['type_label'] = fatalguard_error_type_label( (int) ( $rec['type'] ?? 0 ) );
			}
			$out[] = $rec;
		}
	}
	return $out;
}

/** Like tail() but without scrubbing transforms (used for NDJSON parsing). */
function fatalguard_tail_raw( string $path, int $max ): array {
	$max  = max( 1, min( 2000, $max ) );
	$fp   = @fopen( $path, 'rb' );
	if ( ! $fp ) {
		return array();
	}
	$stat = @fstat( $fp );
	$size = isset( $stat['size'] ) ? (int) $stat['size'] : 0;
	if ( $size <= 0 ) {
		@fclose( $fp );
		return array();
	}
	$read = (int) min( $size, FATALGUARD_DL_BYTE_CAP * 2 );
	@fseek( $fp, -$read, SEEK_END );
	$buffer = @fread( $fp, $read );
	@fclose( $fp );
	if ( false === $buffer || '' === $buffer ) {
		return array();
	}
	if ( $read < $size ) {
		$nl = strpos( $buffer, "\n" );
		if ( false !== $nl ) {
			$buffer = substr( $buffer, $nl + 1 );
		}
	}
	$parts = preg_split( '/\r\n|\r|\n/', rtrim( $buffer, "\r\n" ) );
	return is_array( $parts ) ? array_slice( $parts, -$max ) : array();
}
