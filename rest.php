<?php
/**
 * REST API — fatalguard/v1/debug-logs/*. Every route requires manage_options AND an
 * explicit wp_rest nonce: the always-on localhost application password
 * (zz-localhost-app-password.php) means a cap-only check is bypassable, so we
 * also demand the browser-session nonce.
 *
 * @package FatalGuard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const FATALGUARD_DL_VERSION = '1.3.7';

/** Cap + explicit wp_rest nonce. */
function fatalguard_rest_permission( WP_REST_Request $request ): bool {
	if ( ! current_user_can( 'manage_options' ) ) {
		return false;
	}
	$nonce = $request->get_header( 'x_wp_nonce' );
	if ( empty( $nonce ) ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
	}
	return (bool) wp_verify_nonce( (string) $nonce, 'wp_rest' );
}

function fatalguard_register_rest(): void {
	$ns   = 'fatalguard/v1';
	$perm = 'fatalguard_rest_permission';

	register_rest_route(
		$ns,
		'/debug-logs/ping',
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function () {
				return rest_ensure_response( array( 'ok' => true, 'version' => FATALGUARD_DL_VERSION ) );
			},
		)
	);

	register_rest_route(
		$ns,
		'/debug-logs/sources',
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function () {
				return rest_ensure_response(
					array(
						'sources'      => fatalguard_sources(),
						'status'       => fatalguard_status_report(),
						'file_logging' => array(
							'enabled'  => fatalguard_file_logging_enabled(),
							'writable' => fatalguard_wpconfig_writable(),
						),
					)
				);
			},
		)
	);

	register_rest_route(
		$ns,
		'/debug-logs/tail',
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'args'                => array(
				'source' => array( 'type' => 'string', 'required' => true ),
				'max'    => array( 'type' => 'integer', 'default' => 200 ),
				'q'      => array( 'type' => 'string', 'default' => '' ),
				're'     => array( 'type' => 'boolean', 'default' => false ),
				'group'  => array( 'type' => 'boolean', 'default' => false ),
			),
			'callback'            => 'fatalguard_rest_tail',
		)
	);

	register_rest_route(
		$ns,
		'/debug-logs/clear',
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'args'                => array(
				'source' => array( 'type' => 'string', 'required' => true ),
			),
			'callback'            => 'fatalguard_rest_clear',
		)
	);

	register_rest_route(
		$ns,
		'/debug-logs/clear-mark',
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'args'                => array(
				'source' => array( 'type' => 'string', 'required' => true ),
			),
			'callback'            => 'fatalguard_rest_clear_mark',
		)
	);

	register_rest_route(
		$ns,
		'/debug-logs/file-logging',
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'args'                => array(
				'enabled' => array( 'type' => 'boolean', 'required' => true ),
			),
			'callback'            => 'fatalguard_rest_file_logging',
		)
	);

	register_rest_route(
		$ns,
		'/debug-logs/log-js',
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'args'                => array(
				'message' => array( 'type' => 'string', 'required' => true ),
				'level'   => array( 'type' => 'string', 'default' => 'error' ),
			),
			'callback'            => 'fatalguard_rest_log_js',
		)
	);

	register_rest_route(
		$ns,
		'/debug-logs/tags',
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'args'                => array(
				'source' => array( 'type' => 'string', 'required' => true ),
				'max'    => array( 'type' => 'integer', 'default' => 500 ),
			),
			'callback'            => 'fatalguard_rest_tags',
		)
	);

	register_rest_route(
		$ns,
		'/debug-logs/test-line',
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => 'fatalguard_rest_test_line',
		)
	);

	register_rest_route(
		$ns,
		'/debug-logs/fatals',
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'args'                => array(
				'max'   => array( 'type' => 'integer', 'default' => 100 ),
				'group' => array( 'type' => 'boolean', 'default' => false ),
			),
			'callback'            => function ( WP_REST_Request $r ) {
				$fatals = fatalguard_read_fatals( (int) $r->get_param( 'max' ) );
				if ( $r->get_param( 'group' ) ) {
					return rest_ensure_response(
						array(
							'grouped' => fatalguard_group_fatals( $fatals ),
							'now'     => time(),
						)
					);
				}
				return rest_ensure_response(
					array(
						'fatals' => $fatals,
						'now'    => time(),
					)
				);
			},
		)
	);

	register_rest_route(
		$ns,
		'/debug-logs/test-telegram',
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => function () {
				return rest_ensure_response( fatalguard_telegram_test() );
			},
		)
	);

	// Send a test alert to EVERY configured channel (Telegram + Pro Slack/Discord/
	// webhook/email) and report per-channel status. Powers the "Send test to all
	// channels" button. Telegram is always included (free); Pro channels only when
	// the license is active and configured.
	register_rest_route(
		$ns,
		'/debug-logs/test-alerts',
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => 'fatalguard_rest_test_alerts',
		)
	);

	register_rest_route(
		$ns,
		'/debug-logs/fatals-clear',
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => 'fatalguard_rest_fatals_clear',
		)
	);
}

/**
 * Send a live test alert to every configured channel and return per-channel
 * results. Telegram (free) is always attempted when configured; the Pro channels
 * (Slack/Discord/webhook/email) are added when the license is active. Shape:
 * { results: [ { channel, ok, message } ], any: bool }.
 */
function fatalguard_rest_test_alerts( WP_REST_Request $request ) {
	$results = array();

	// Telegram — the free channel.
	if ( function_exists( 'fatalguard_telegram_configured' ) && fatalguard_telegram_configured() ) {
		$t = fatalguard_telegram_test();
		$results[] = array(
			'channel' => 'Telegram',
			'ok'      => ! empty( $t['ok'] ),
			'message' => isset( $t['message'] ) ? (string) $t['message'] : '',
		);
	}

	// Pro channels (only if the license is active + a channel is configured).
	if ( function_exists( 'fatalguard_pro_channels_test' ) ) {
		foreach ( fatalguard_pro_channels_test() as $channel => $r ) {
			$results[] = array(
				'channel' => (string) $channel,
				'ok'      => ! empty( $r['ok'] ),
				'message' => isset( $r['message'] ) ? (string) $r['message'] : '',
			);
		}
	}

	return rest_ensure_response(
		array(
			'results' => $results,
			'any'     => ! empty( $results ),
		)
	);
}

/** Empty the captured-fatals log (fatals.ndjson) and reset the alert-dispatch offset. */
function fatalguard_rest_fatals_clear( WP_REST_Request $request ) {
	$path = fatalguard_fatals_log();
	if ( ! @is_file( $path ) ) {
		return rest_ensure_response( array( 'cleared' => true ) ); // nothing captured yet
	}
	// B122: coordinate the truncate + marker reset with the sweep (hooks.php) and the
	// crash-path rotation (dropin.php) under the shared .dispatched.lock. Without it a
	// concurrent sweep can write a stale byte-size over our freshly-reset "0", re-
	// anchoring past post-clear fatals and swallowing them. WP context, so a blocking
	// lock is fine (unlike the crash path's LOCK_NB).
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- advisory flock lock file; WP_Filesystem has no file-locking API.
	$lock = @fopen( fatalguard_dir() . '/.dispatched.lock', 'c' );
	if ( $lock ) {
		@flock( $lock, LOCK_EX );
	}
	$ok = fatalguard_clear( $path );
	if ( $ok ) {
		// Reset the sweeper's byte offset to 0 (the log is now empty). Writing "0" — not
		// unlinking — is deliberate: a missing marker reads as "first run" and re-anchors
		// the NEXT sweep to EOF, which would swallow any fatal captured between this clear
		// and that sweep. "0" means every post-clear fatal is new and dispatches. No
		// LOCK_EX here — the held .dispatched.lock is the sole marker guard.
		@file_put_contents( fatalguard_dir() . '/.dispatched', '0' );
	}
	if ( $lock ) {
		@flock( $lock, LOCK_UN );
		@fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the advisory flock handle opened above.
	}
	if ( ! $ok ) {
		return new WP_Error( 'fatalguard_dl_not_clearable', 'The fatals log cannot be cleared.', array( 'status' => 400 ) );
	}
	return rest_ensure_response( array( 'cleared' => true ) );
}

function fatalguard_rest_tail( WP_REST_Request $request ) {
	$id   = (string) $request->get_param( 'source' );
	$max  = (int) $request->get_param( 'max' );
	$path = fatalguard_resolve_source( $id );
	if ( '' === $path ) {
		// Distinguish "stream" sources (e.g. /dev/stderr) from genuinely unknown.
		foreach ( fatalguard_sources() as $s ) {
			if ( $s['id'] === $id ) {
				return rest_ensure_response(
					array(
						'source' => $id,
						'kind'   => $s['kind'],
						'lines'  => array(),
						'note'   => 'stream' === $s['kind']
							? 'This log streams to the container (e.g. /dev/stderr) and is not a tailable file. View it with: npx wp-env logs all'
							: 'Log file not found yet.',
					)
				);
			}
		}
		return new WP_Error( 'fatalguard_dl_unknown_source', 'Unknown log source.', array( 'status' => 400 ) );
	}
	clearstatcache( true, $path );
	$lines = fatalguard_tail( $path, $max );

	$q = (string) $request->get_param( 'q' );
	if ( '' !== trim( $q ) ) {
		$lines = fatalguard_filter_lines( $lines, $q, (bool) $request->get_param( 're' ) );
	}

	$resp = array(
		'source' => $id,
		'kind'   => 'file',
		// mtime ≈ when the last line was written; with `now` (same clock) the
		// client fades the collapsed peek by the entry's age, skew-free.
		'mtime'  => (int) ( @filemtime( $path ) ?: 0 ),
		'now'    => time(),
	);
	if ( $request->get_param( 'group' ) ) {
		$resp['grouped'] = fatalguard_group_lines( $lines );
	} else {
		$resp['lines'] = $lines;
	}
	return rest_ensure_response( $resp );
}

function fatalguard_rest_clear( WP_REST_Request $request ) {
	$id   = (string) $request->get_param( 'source' );
	$path = fatalguard_resolve_source( $id );
	if ( '' === $path ) {
		return new WP_Error( 'fatalguard_dl_unknown_source', 'Unknown log source.', array( 'status' => 400 ) );
	}
	$ok = fatalguard_clear( $path );
	if ( ! $ok ) {
		return new WP_Error( 'fatalguard_dl_not_clearable', 'This source cannot be cleared.', array( 'status' => 400 ) );
	}
	return rest_ensure_response( array( 'cleared' => true, 'lines' => array() ) );
}

/**
 * Self-check: write a unique server-generated token via error_log(), then
 * re-tail the managed log to prove WP → file → reader works end to end.
 */
function fatalguard_rest_test_line( WP_REST_Request $request ) {
	fatalguard_apply(); // make sure error_log points where we read
	$marker = 'FATALGUARD_DL_SELFTEST_' . wp_generate_password( 16, false, false );
	error_log( '[fatalguard] self-check ' . $marker ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate write to the managed PHP error log to verify logging works; this plugin owns that log, not debug output.

	$observed = false;
	$lines    = fatalguard_tail( fatalguard_php_log(), 50 );
	foreach ( $lines as $line ) {
		if ( false !== strpos( $line, $marker ) ) {
			$observed = true;
			break;
		}
	}

	$state = fatalguard_state();
	return rest_ensure_response(
		array(
			'written'  => true,
			'observed' => $observed,
			'marker'   => $marker,
			'reason'   => $observed ? 'ok' : fatalguard_selftest_reason(),
			'path'     => fatalguard_relativize( fatalguard_php_log() ),
		)
	);
}

/** Best-effort explanation when the self-check line did not appear. */
function fatalguard_selftest_reason(): string {
	if ( '0' === (string) @ini_get( 'log_errors' ) || '' === (string) @ini_get( 'log_errors' ) ) {
		return 'log_errors is off — PHP is not writing to a file.';
	}
	$dir = fatalguard_dir();
	if ( ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
		return 'The log directory is not writable: ' . fatalguard_relativize( $dir );
	}
	return 'Unknown — the line did not appear in the managed log.';
}

/**
 * Logging diagnosis for the Status tab. Surfaces the exact reasons the old tool
 * showed nothing (logging off, conflict, stream target, disabled handler).
 */
function fatalguard_status_report(): array {
	$state = fatalguard_state();
	return array(
		'log_errors_ini'   => (string) @ini_get( 'log_errors' ),
		'error_log_ini'    => (string) @ini_get( 'error_log' ),
		'managed_log'      => fatalguard_relativize( fatalguard_php_log() ),
		'managed_writable' => wp_is_writable( fatalguard_dir() ),
		'wp_debug'         => defined( 'WP_DEBUG' ) && WP_DEBUG,
		'wp_debug_display' => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
		'display_errors'   => (bool) $state['display_errors'],
		'fatal_capture'    => (bool) $state['fatal_capture'],
		'dropin_ours'         => fatalguard_dropin_is_ours(),
		'dropin_foreign'      => fatalguard_foreign_dropin_present(),
		// B061: expose whether the on-disk drop-in is current (no write failure).
		'dropin_current'      => ! (bool) get_transient( 'fatalguard_dropin_stale' ),
		'dropin_write_failed' => (bool) get_transient( 'fatalguard_dropin_stale' ),
		'disabled'            => fatalguard_is_disabled(),
		'telegram'         => fatalguard_telegram_configured() ? 'configured' : 'not_configured',
		'environment'      => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown',
	);
}

/** Add a clear-mark line to the log instead of truncating. */
function fatalguard_rest_clear_mark( WP_REST_Request $request ) {
	$id   = (string) $request->get_param( 'source' );
	$path = fatalguard_resolve_source( $id );
	if ( '' === $path ) {
		return new WP_Error( 'fatalguard_dl_unknown_source', 'Unknown log source.', array( 'status' => 400 ) );
	}
	fatalguard_apply(); // ensure error_log points right place
	error_log( '[FATALGUARD_CLEAR] Cleared at ' . gmdate( 'c' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate marker written to the managed log on clear; this plugin owns that log.
	return rest_ensure_response( array( 'marked' => true ) );
}

/**
 * Toggle protected file logging from the overlay's bottom bar. An explicit
 * admin action, same mechanism as the settings toggle: the wp-config writer
 * sets/restores WP_DEBUG_LOG and core applies it. A product overlay
 * (FatalGuard) can take over via the fatalguard_set_file_logging filter
 * so its own settings option stays in sync — it returns true or an error
 * string; null means "not handled, use the direct writer".
 */
function fatalguard_rest_file_logging( WP_REST_Request $request ) {
	$enabled = (bool) $request->get_param( 'enabled' );

	$result = apply_filters( 'fatalguard_set_file_logging', null, $enabled );
	if ( null === $result ) {
		$display = defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY;
		$result  = fatalguard_wpconfig_sync( $enabled, $display );
	}
	if ( true !== $result ) {
		$msg = is_string( $result ) && '' !== $result ? $result : __( 'Could not update wp-config.php.', 'fatalguard' );
		return new WP_Error( 'fatalguard_dl_wpconfig', $msg, array( 'status' => 500 ) );
	}
	if ( $enabled ) {
		fatalguard_apply(); // the log dir must exist before PHP writes to it
	}
	return rest_ensure_response( array( 'enabled' => $enabled ) );
}

/** Log a JS error/warning from the browser. */
function fatalguard_rest_log_js( WP_REST_Request $request ) {
	$msg   = (string) $request->get_param( 'message' );
	$level = (string) $request->get_param( 'level' );
	if ( '' === $msg ) {
		return new WP_Error( 'fatalguard_dl_empty_message', 'Message is required.', array( 'status' => 400 ) );
	}

	// Server-side rate limit: max 60 writes per user per 60-second window.
	$rate_key   = 'fatalguard_dl_js_rate_' . get_current_user_id();
	$rate_count = (int) get_transient( $rate_key );
	if ( $rate_count >= 60 ) {
		return new WP_Error( 'fatalguard_dl_rate_limit', 'Rate limited.', array( 'status' => 429 ) );
	}
	set_transient( $rate_key, $rate_count + 1, 60 );

	$level   = preg_replace( '/[^a-z]/i', '', $level );
	$clean   = str_replace( array( "\r", "\n" ), ' ', fatalguard_scrub( $msg ) );
	$log_msg = '[' . gmdate( 'c' ) . '] [JS ' . strtoupper( $level ) . '] ' . substr( $clean, 0, 500 ) . "\n";
	fatalguard_ensure_logdir();
	$js_log = fatalguard_dir() . '/js-error.log';

	// Size cap: front-trim to 256 KB when the file exceeds 512 KB.
	clearstatcache( true, $js_log );
	$size = (int) @filesize( $js_log );
	if ( $size > 524288 ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming tail via fseek; WP_Filesystem reads whole files only.
		$fp = @fopen( $js_log, 'rb' );
		if ( $fp ) {
			fseek( $fp, $size - 262144 );
			$tail = (string) stream_get_contents( $fp );
			fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the fseek tail handle above.
			// Drop the first (partial) line.
			$nl = strpos( $tail, "\n" );
			if ( false !== $nl ) {
				$tail = "\u{2026}[older entries trimmed]\n" . substr( $tail, $nl + 1 );
			}
			@file_put_contents( $js_log, $tail, LOCK_EX );
		}
	}

	@file_put_contents( $js_log, $log_msg, FILE_APPEND | LOCK_EX );
	return rest_ensure_response( array( 'logged' => true ) );
}

/** Extract unique [tag] patterns from a source's logs. */
function fatalguard_rest_tags( WP_REST_Request $request ) {
	$id   = (string) $request->get_param( 'source' );
	$max  = (int) $request->get_param( 'max' );
	$path = fatalguard_resolve_source( $id );
	if ( '' === $path ) {
		return new WP_Error( 'fatalguard_dl_unknown_source', 'Unknown log source.', array( 'status' => 400 ) );
	}
	$lines = fatalguard_tail( $path, $max );
	$tags  = array();
	foreach ( $lines as $line ) {
		if ( preg_match_all( '/\[([a-zA-Z0-9_-]+)\]/', $line, $m ) ) {
			foreach ( $m[1] as $tag ) {
				$tags[ $tag ] = true;
			}
		}
	}
	return rest_ensure_response( array( 'tags' => array_keys( $tags ) ) );
}
