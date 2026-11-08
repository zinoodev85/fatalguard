<?php
/**
 * Unified logging state — the SINGLE authority that decides and applies
 * log_errors / error_log / display_errors for fatalguard.
 *
 * Before this module, inc/error_display.php would `@ini_set('log_errors','0')`
 * when display-php-errors was on, silently killing file logging (the root of
 * "the debug tool shows nothing"). Here, file logging is ALWAYS on and display
 * is an orthogonal toggle, so the two can never conflict again.
 *
 * Path helpers are boot-cheap (only constants) so the self-written drop-in can
 * be handed the same resolved paths at heal-time.
 *
 * @package FatalGuard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- Creates the private per-site log dir with
// mkdir(); WP_Filesystem may prompt for FS credentials and is not always available on the
// early request path where logging is enabled.

/**
 * Absolute path to the private, per-site log directory.
 *
 * Lives inside the uploads directory (the location WordPress sanctions for plugin
 * data — never wp-content root), resolved at runtime with wp_upload_dir() so it
 * honours custom uploads paths and multisite. The directory name carries an
 * AUTH_KEY-derived suffix so the path is not guessable on hosts where the
 * `.htaccess` deny is ignored (nginx/CapRover), and it is deterministic — both
 * this (plugin) context and the boot drop-in resolve the same value, and the
 * installer bakes it into the drop-in.
 *
 * NOTE: this function is ONLY ever called in a fully-booted WordPress context
 * (plugins_loaded and later). The pre-boot fatal drop-in never calls it — it reads
 * the baked FATALGUARD_DL_DIR constant — so wp_upload_dir() is always safe to call here.
 */
function fatalguard_dir(): string {
	static $dir = null;
	if ( null !== $dir ) {
		return $dir;
	}
	// The second seed component is a per-site identity string (defends dir-name
	// guessability when AUTH_KEY is missing/shared), not a path lookup.
	$seed   = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . '|fatalguard|' . ( realpath( WP_CONTENT_DIR ) ?: WP_CONTENT_DIR );
	$suffix = substr( hash( 'sha256', $seed ), 0, 12 );

	// Only-booted context (see docblock): wp_upload_dir() always exists. Its basedir
	// is valid even when 'error' is set (that flags a failed mkdir, which our own
	// ensure-dir handles later) — so no hardcoded uploads fallback is needed.
	$uploads = wp_upload_dir( null, false );
	$base    = ( is_array( $uploads ) && ! empty( $uploads['basedir'] ) ) ? (string) $uploads['basedir'] : '';
	$dir     = rtrim( $base, '/\\' ) . '/fatalguard-debug-' . $suffix;

	return $dir;
}

/** The PHP error_log target (WordPress + PHP tabs read this). */
function fatalguard_php_log(): string {
	return fatalguard_dir() . '/php-error.log';
}

/** One JSON record per captured fatal (Fatals tab reads this). */
function fatalguard_fatals_log(): string {
	return fatalguard_dir() . '/fatals.ndjson';
}

/** No-SSH kill switch: when this file exists, everything self-disables. */
function fatalguard_disable_flag(): string {
	return fatalguard_dir() . '/DISABLE';
}

/** Self-disarm sentinel: written before custom fatal work, cleared after. */
function fatalguard_sentinel(): string {
	return fatalguard_dir() . '/.pending';
}

/** True when the whole feature has been killed via the DISABLE flag. */
function fatalguard_is_disabled(): bool {
	return is_file( fatalguard_disable_flag() );
}

/**
 * Whether WordPress will actually arm a fatal handler (and therefore our
 * drop-in). False when the site sets WP_DISABLE_FATAL_ERROR_HANDLER or filters
 * `wp_fatal_error_handler_enabled` off — the Status tab surfaces this instead
 * of falsely reporting "armed".
 */
function fatalguard_fatal_capture_enabled(): bool {
	if ( defined( 'WP_DISABLE_FATAL_ERROR_HANDLER' ) && WP_DISABLE_FATAL_ERROR_HANDLER ) {
		return false;
	}
	if ( function_exists( 'apply_filters' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentionally reads WordPress core's own filter (wp_fatal_error_handler_enabled) so we honour the same on/off switch core uses; not a plugin-defined hook.
		return (bool) apply_filters( 'wp_fatal_error_handler_enabled', true );
	}
	return true;
}

/**
 * Whether PHP file logging is routed to the plugin's protected log. The single
 * source of truth is wp-config.php: the settings toggle writes WP_DEBUG_LOG (via
 * the wp-config writer, an explicit user action) and WordPress core applies it in
 * wp_debug_mode(). The plugin never touches PHP configuration at runtime.
 */
function fatalguard_file_logging_enabled(): bool {
	$enabled = defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG )
		&& rtrim( WP_DEBUG_LOG, '/\\' ) === rtrim( fatalguard_php_log(), '/\\' );
	if ( function_exists( 'apply_filters' ) ) {
		$enabled = (bool) apply_filters( 'fatalguard_file_logging', $enabled );
	}
	return $enabled;
}

/**
 * Resolve the current logging state — read-only, straight from the WP_DEBUG_*
 * constants that the wp-config writer manages on the user's behalf.
 *
 * @return array{display_errors:bool,log_errors:bool,error_log_path:string,fatal_capture:bool}
 */
function fatalguard_state(): array {
	return array(
		'display_errors' => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
		'log_errors'     => fatalguard_file_logging_enabled(),
		'error_log_path' => fatalguard_php_log(),
		'fatal_capture'  => fatalguard_fatal_capture_enabled(),
	);
}

/**
 * Ensure the protected log directory exists so PHP (routed here by the
 * user-written WP_DEBUG_LOG constant) can open its log file. The plugin does
 * NOT configure PHP here — no ini_set, no error_reporting, ever: routing and
 * display are wp-config constants (see wp-config-writer.php) applied by core.
 */
function fatalguard_apply(): void {
	if ( fatalguard_is_disabled() ) {
		return;
	}
	$dir = fatalguard_dir();
	if ( ! is_dir( $dir ) ) {
		if ( function_exists( 'wp_mkdir_p' ) ) {
			@wp_mkdir_p( $dir );
		} else {
			@mkdir( $dir, 0755, true );
		}
	}
}
