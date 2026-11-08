<?php
/**
 * General-purpose hook seams for the debug-logs module. Keeps the core free of
 * commercial code: Pro modules (channels, multi-target, multisite, stealth,
 * digest) live in pro/ and hang off these seams. In the fatalguard context
 * everything is unlocked; the FatalGuard standalone overrides the gate filter
 * with its Freemius license state.
 *
 * @package FatalGuard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- Reads the live log tail for the admin
// viewer via direct file handles (fopen/fread/fclose); WP_Filesystem has no tail primitive
// for reading only the last bytes of a large, actively-written log.

/**
 * The single Pro capability gate. Defaults TRUE — fatalguard runs every feature
 * unlocked. FatalGuard's bootstrap adds a filter returning the Freemius license
 * state, so free installs get FALSE and Pro installs get TRUE. Pro modules call
 * this before doing any work.
 */
function fatalguard_can_use_pro(): bool {
	return (bool) apply_filters( 'fatalguard_can_use_pro', true );
}

/**
 * Fire the per-fatal seam that Pro alert channels (Slack/email/Discord/webhook)
 * listen on. Called in WP context for each newly-captured fatal record (the
 * pre-WP drop-in can't reach plugin-registered channels, so dispatch happens
 * here, off the persisted record). No-op until a listener is attached.
 *
 * @param array $record a fatalguard_build_record()-shaped fatal.
 */
function fatalguard_dispatch_fatal( array $record ): void {
	/**
	 * @param array $record The captured fatal (type/message/file/line/origin/…).
	 */
	do_action( 'fatalguard_fatal_captured', $record );
}

/**
 * On each WP load, dispatch fatals captured since the last check to the
 * fatalguard_fatal_captured action (Pro channels listen). A byte offset
 * in .dispatched makes each fatal fire exactly once; it initializes to EOF on
 * first run so pre-existing history isn't replayed. No listeners → no work.
 */
function fatalguard_sweep_new_fatals(): void {
	// Run at most once per request: it's hooked on both plugins_loaded AND init
	// (init is the fallback when a load-time fatal killed plugins_loaded), so the
	// second hook must not re-sweep and burn a second batch-cap slice. (B035/B012)
	static $ran = false;
	if ( $ran ) {
		return;
	}
	$ran = true;
	if ( ! function_exists( 'fatalguard_fatals_log' ) ) {
		return;
	}
	$path = fatalguard_fatals_log();
	if ( ! @is_file( $path ) ) {
		return;
	}
	// B016/B062/B117: serialize concurrent sweeps across requests. Two overlapping
	// requests would each read the same .dispatched offset and dispatch the same
	// records twice. Take a NON-BLOCKING exclusive lock on a sidecar .dispatched.lock;
	// the loser returns and its backlog is covered by the next sweep. LOCK_NB (never
	// block) so a visitor request is never stalled behind another sweep. Held for the
	// whole marker read-modify-write below; released when $lock_fp goes out of scope
	// on any return. If the lock file can't be opened (unwritable dir), degrade to
	// running unlocked rather than blocking — never worse than today's behaviour.
	$lock_fp = @fopen( fatalguard_dir() . '/.dispatched.lock', 'c' );
	if ( $lock_fp && ! @flock( $lock_fp, LOCK_EX | LOCK_NB ) ) {
		@fclose( $lock_fp );
		return; // another sweep holds the lock; it will cover this backlog
	}
	$marker = fatalguard_dir() . '/.dispatched';
	$size   = (int) @filesize( $path );
	// Only a well-formed integer counts as a real offset. An empty or corrupt
	// marker (interrupted LOCK_EX write) must NOT read as 0 — that would replay the
	// entire log with duplicate alerts. Treat it as "first run" instead.
	$raw    = is_file( $marker ) ? (string) @file_get_contents( $marker ) : '';
	$offset = ctype_digit( trim( $raw ) ) ? (int) trim( $raw ) : null;

	// First run, truncation/rotation, or a corrupt marker: re-anchor to EOF, don't
	// replay. Advancing the marker here (even with no listener yet) is what stops a
	// free→Pro upgrade from flooding the first sweep with the whole accrued log.
	if ( null === $offset || $offset > $size ) {
		@file_put_contents( $marker, (string) $size, LOCK_EX );
		return;
	}
	if ( $offset === $size ) {
		return;
	}

	// No Pro listener attached (free build, pro/ stripped): advance the marker to
	// EOF WITHOUT dispatching, so the offset tracks the log even while nothing
	// consumes it. Prevents the free→Pro replay flood.
	if ( ! has_action( 'fatalguard_fatal_captured' ) ) {
		@file_put_contents( $marker, (string) $size, LOCK_EX );
		return;
	}

	// B047: verify the .dispatched marker is writable before dispatching; if not,
	// skip dispatch entirely — an unwritable marker would cause every subsequent
	// request to re-dispatch the same fatals (duplicate-flood variant).
	if ( is_file( $marker ) && ! is_writable( $marker ) ) {
		return;
	}

	$fp = @fopen( $path, 'rb' );
	if ( ! $fp ) {
		return;
	}
	@fseek( $fp, $offset );
	$new = @fread( $fp, $size - $offset );
	@fclose( $fp );

	if ( false === $new || '' === $new ) {
		// Nothing readable in [offset,size): re-anchor to EOF so a transient read
		// glitch can't wedge the sweep on the same offset forever.
		@file_put_contents( $marker, (string) $size, LOCK_EX );
		return;
	}

	// Cap work per sweep and advance the marker PER SUCCESSFULLY-DISPATCHED record
	// (never to EOF up-front). A mid-loop timeout/kill then leaves the marker at the
	// last delivered record, so the undelivered remainder is retried next sweep
	// instead of being lost. Splitting on real newlines lets us track the exact byte
	// offset each line ends at (line bytes + the 1-byte separator). (B012/B020/B028/B049/B127)
	$batch_cap  = 25;
	$dispatched = 0;
	$cursor     = $offset;
	$parts      = preg_split( '/\n/', $new );
	$last       = count( $parts ) - 1;
	foreach ( $parts as $i => $raw_line ) {
		// Every part but the last consumed one trailing "\n" byte in the file. The
		// final part has no separator unless the read ended exactly on a newline
		// (then it's an empty trailing element and we skip it below).
		$consumed = strlen( $raw_line ) + ( $i < $last ? 1 : 0 );

		$line = trim( $raw_line );
		if ( '' === $line ) {
			// Blank/whitespace line — nothing to dispatch, but it still occupied
			// bytes; step the cursor past it and persist so we never re-read it.
			$cursor += $consumed;
			@file_put_contents( $marker, (string) $cursor, LOCK_EX );
			continue;
		}

		$rec = json_decode( $line, true );
		if ( ! is_array( $rec ) ) {
			// Corrupt/non-JSON line: skip past it (advance marker) so it can't wedge
			// the sweep on the same offset every request.
			$cursor += $consumed;
			@file_put_contents( $marker, (string) $cursor, LOCK_EX );
			continue;
		}

		// The only writer of fatals.ndjson is the pre-WP drop-in, whose lean
		// record omits origin + type_label. Backfill them here (WP context) so
		// Pro "Likely cause" and origin-routed Telegram targets work on real
		// fatals — otherwise every routed target silently never matches.
		if ( empty( $rec['origin'] ) && ! empty( $rec['file'] ) && function_exists( 'fatalguard_classify_source' ) ) {
			$rec['origin'] = fatalguard_classify_source( (string) $rec['file'] );
		}
		if ( empty( $rec['type_label'] ) && function_exists( 'fatalguard_error_type_label' ) ) {
			$rec['type_label'] = fatalguard_error_type_label( (int) ( $rec['type'] ?? 0 ) );
		}

		// Re-scrub the message before any channel send: the drop-in's lean scrub()
		// covers only 5 constant values + sk-/Bearer. The canonical scrub also
		// covers DB_USER, the 4 salts, and pattern rules (api_key/password/
		// Set-Cookie/bot-token). Apply it here so every Pro channel gets the
		// fully-scrubbed text regardless of what the drop-in missed. (B031)
		if ( ! empty( $rec['message'] ) && function_exists( 'fatalguard_scrub' ) ) {
			$rec['message'] = fatalguard_scrub( (string) $rec['message'] );
		}

		// Isolate each record's dispatch: a listener that throws (e.g. a disabled
		// fsockopen on PHP 8) must not fatal the sweep and turn every subsequent
		// request into a 500. On throw we DO NOT advance the marker for this record,
		// so it is retried on the next sweep rather than silently dropped. (B003)
		try {
			fatalguard_dispatch_fatal( $rec );
		} catch ( \Throwable $e ) {
			// Best-effort: never let a broken listener take down the request. We
			// STOP the sweep here (not `continue`): the marker still points at this
			// record's start offset, so it — and everything after it — is retried on
			// the next sweep instead of being silently skipped. Continuing would
			// either desync the byte cursor or loop the whole backlog every request.
			break;
		}

		// Delivered: commit the marker past this record only now. A crash before
		// this line means the record is redelivered (at-least-once), never lost.
		$cursor += $consumed;
		@file_put_contents( $marker, (string) $cursor, LOCK_EX );

		if ( ++$dispatched >= $batch_cap ) {
			// Batch cap hit: leave the remaining backlog for the next sweep so one
			// visitor's request never blocks on the whole backlog of sends.
			break;
		}
	}
}

/**
 * Plain-text one-fatal summary shared by every alert channel (Telegram targets,
 * Slack, Discord, email, webhook). Scrubbed message + origin + location + site.
 */
function fatalguard_format_alert_text( array $rec ): string {
	$site   = function_exists( 'home_url' ) ? home_url( '/' ) : '';
	$label  = isset( $rec['type_label'] ) ? (string) $rec['type_label'] : 'Fatal error';
	$msg    = isset( $rec['message'] ) ? (string) $rec['message'] : '';
	$where  = trim( ( isset( $rec['file'] ) ? (string) $rec['file'] : '' ) . ':' . ( isset( $rec['line'] ) ? (int) $rec['line'] : 0 ), ':' );
	$origin = ! empty( $rec['origin'] ) ? (string) $rec['origin'] : '';

	// 1) Title.
	$text = '🔴 ' . $label . ' on ' . $site;
	// 2) Likely cause (plugin/theme/core) — humanize the origin token.
	if ( '' !== $origin ) {
		// Transform "plugin:akismet" → 'the "akismet" plugin', "theme:flatsome" → 'the flatsome theme'.
		if ( 0 === strpos( $origin, 'plugin:' ) ) {
			$name        = substr( $origin, strlen( 'plugin:' ) );
			$human_cause = 'the "' . $name . '" plugin';
		} elseif ( 0 === strpos( $origin, 'theme:' ) ) {
			$name        = substr( $origin, strlen( 'theme:' ) );
			$human_cause = 'the ' . $name . ' theme';
		} else {
			$human_cause = $origin; // 'core', 'mu-plugin', etc. — pass through as-is
		}
		$text .= "\n\n⚠ Likely cause: " . $human_cause;
	}
	// 3) Error + location at the very bottom.
	$detail = array_filter( array( $msg, $where ) );
	if ( $detail ) {
		$text .= "\n\n—— error ——\n" . implode( "\n", $detail );
	}
	// 4) Let a recovery engine append a one-tap "turn it off" link for plugin
	//    culprits (FatalGuard overlay hooks this; internal fatalguard installs don't,
	//    so nothing is appended there).
	$text = apply_filters( 'fatalguard_alert_text', $text, $rec );
	return trim( $text );
}
