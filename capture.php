<?php
/**
 * Capture helpers — build the minimal allowlisted fatal record and scrub
 * secrets/PII out of any text before it is shown in the browser or shipped to
 * Telegram. Pure-ish (only reads constants); no side effects.
 *
 * The boot drop-in inlines its own minimal copy of the scrub (it must be
 * version-independent of the plugin); this is the canonical copy used by the
 * reader (defense-in-depth on read) and the normal-context Telegram sender.
 *
 * @package FatalGuard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the minimal, allowlisted record for a captured error. NEVER includes
 * $_POST / cookies / headers / backtraces.
 *
 * @param array $error error_get_last()-shaped array (type/message/file/line).
 * @return array
 */
function fatalguard_build_record( array $error ): array {
	$url = '';
	if ( isset( $_SERVER['REQUEST_URI'] ) ) {
		$uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		$url = strtok( $uri, '?' ); // strip query string (may carry tokens)
	}
	$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';

	$rel_file = fatalguard_relativize( isset( $error['file'] ) ? (string) $error['file'] : '' );

	return array(
		'time'        => gmdate( 'c' ),
		'type'        => isset( $error['type'] ) ? (int) $error['type'] : 0,
		'type_label'  => fatalguard_error_type_label( isset( $error['type'] ) ? (int) $error['type'] : 0 ),
		'message'     => fatalguard_scrub( isset( $error['message'] ) ? (string) $error['message'] : '' ),
		'file'        => $rel_file,
		'line'        => isset( $error['line'] ) ? (int) $error['line'] : 0,
		'origin'      => fatalguard_classify_source( $rel_file ),
		'host'        => $host,
		'url'         => $url,
		'php_version' => PHP_VERSION,
	);
}

/**
 * Attribute a wp-content-relative path to its origin: a specific plugin, theme,
 * or WordPress core. Pure string logic — no filesystem. Returns 'plugin:<slug>',
 * 'theme:<slug>', 'core', or 'unknown'.
 */
function fatalguard_classify_source( string $rel_path ): string {
	if ( '' === $rel_path ) {
		return 'unknown';
	}
	$p = ltrim( str_replace( '\\', '/', $rel_path ), '/' );

	if ( preg_match( '#^wp-content/(?:mu-plugins|plugins)/([^/]+)#', $p, $m ) ) {
		// Flat mu-plugin files (mu-plugins/loader.php) have no dir — drop .php.
		return 'plugin:' . preg_replace( '/\.php$/i', '', $m[1] );
	}
	if ( preg_match( '#^wp-content/themes/([^/]+)#', $p, $m ) ) {
		return 'theme:' . $m[1];
	}
	if ( preg_match( '#^(?:wp-admin|wp-includes)/#', $p ) || preg_match( '#(?:^|/)wp-settings\.php$#', $p ) ) {
		return 'core';
	}
	return 'unknown';
}

/** Human label for a PHP error-type bitmask value. */
function fatalguard_error_type_label( int $type ): string {
	$map = array(
		E_ERROR             => 'Fatal error',
		E_PARSE             => 'Parse error',
		E_CORE_ERROR        => 'Core error',
		E_COMPILE_ERROR     => 'Compile error',
		E_USER_ERROR        => 'User error',
		E_RECOVERABLE_ERROR => 'Recoverable error',
		E_WARNING           => 'Warning',
		E_NOTICE            => 'Notice',
		E_DEPRECATED        => 'Deprecated',
	);
	return $map[ $type ] ?? ( 'Error (' . $type . ')' );
}

/** True for the error types that produce WP's "critical error" page. */
function fatalguard_is_fatal_type( int $type ): bool {
	return in_array( $type, array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true );
}

/** Collapse absolute paths to a wp-content-relative form. */
function fatalguard_relativize( string $path ): string {
	if ( '' === $path ) {
		return $path;
	}
	$base = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '';
	if ( '' !== $base && 0 === strpos( $path, $base ) ) {
		return 'wp-content' . substr( $path, strlen( $base ) );
	}
	// Site root approximated as wp-content's parent (display-shortening only; on
	// layouts where wp-content lives elsewhere the path just stays unshortened).
	$root = '' !== $base ? rtrim( dirname( $base ), '/\\' ) . '/' : '';
	if ( '' !== $root && 0 === strpos( $path, $root ) ) {
		return substr( $path, strlen( $root ) );
	}
	return $path;
}

/**
 * The literal secret VALUES to redact wherever they appear in log text —
 * resolved once from wp-config constants plus the Telegram creds.
 *
 * @return string[]
 */
function fatalguard_secret_values(): array {
	static $values = null;
	if ( null !== $values ) {
		return $values;
	}
	$values    = array();
	$constants = array( 'DB_PASSWORD', 'DB_USER', 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' );
	foreach ( $constants as $c ) {
		if ( defined( $c ) ) {
			$v = (string) constant( $c );
			if ( strlen( $v ) >= 6 ) {
				$values[] = $v;
			}
		}
	}
	if ( function_exists( 'fatalguard_telegram_config' ) ) {
		$cfg = fatalguard_telegram_config();
		foreach ( array( 'bot_token', 'chat_id' ) as $k ) {
			if ( ! empty( $cfg[ $k ] ) && strlen( (string) $cfg[ $k ] ) >= 6 ) {
				$values[] = (string) $cfg[ $k ];
			}
		}
	}
	// Longest first so a value that contains another is redacted whole.
	usort(
		$values,
		static function ( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		}
	);

	return $values;
}

/**
 * Redact secrets/PII from a single string. Pattern-based scrubbing for common
 * credential shapes, plus exact-value redaction of known constants/tokens.
 */
function fatalguard_scrub( string $text ): string {
	if ( '' === $text ) {
		return $text;
	}

	foreach ( fatalguard_secret_values() as $secret ) {
		if ( '' !== $secret ) {
			$text = str_replace( $secret, '[redacted]', $text );
		}
	}

	$patterns = array(
		'/(Authorization:\s*Bearer\s+)[A-Za-z0-9._\-]+/i' => '$1[redacted]',
		'/\bsk-[A-Za-z0-9]{12,}\b/'                       => '[redacted]',
		'/(api[_-]?key["\']?\s*[:=]\s*["\']?)[A-Za-z0-9._\-]{8,}/i' => '$1[redacted]',
		'/(pass(word)?["\']?\s*[:=]\s*["\']?)[^\s"\'&]{3,}/i'       => '$1[redacted]',
		'/(Set-Cookie:\s*)[^\r\n]+/i'                     => '$1[redacted]',
		'/\bbot[0-9]{6,}:[A-Za-z0-9_\-]{20,}\b/'          => 'bot[redacted]',
	);
	foreach ( $patterns as $pattern => $replacement ) {
		$out = preg_replace( $pattern, $replacement, $text );
		if ( null !== $out ) {
			$text = $out;
		}
	}

	return $text;
}

/**
 * Scrub + length-cap an array of log lines for display.
 *
 * @param string[] $lines
 * @return string[]
 */
function fatalguard_scrub_lines( array $lines ): array {
	$out = array();
	foreach ( $lines as $line ) {
		$line = (string) $line;
		if ( strlen( $line ) > 8192 ) {
			$line = substr( $line, 0, 8192 ) . '…[truncated]';
		}
		$out[] = fatalguard_scrub( $line );
	}
	return $out;
}
