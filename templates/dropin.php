<?php
/**
 * FatalGuard — fatal-error drop-in (SELF-WRITTEN; safe to delete).
 * Marker: FatalGuard_DL_Dropin_Handler — MUST stay in the first 4KB: the installer's
 * fatalguard_dropin_is_ours() identifies our drop-in by finding this
 * string in the file head (so it never clobbers a third-party drop-in).
 *
 * WordPress includes this at wp-settings.php (before wp_debug_mode and
 * mu-plugins) when a fatal handler is enabled. It is intentionally
 * self-contained — it calls NO plugin code — so a version skew between the
 * on-disk copy and the plugin can never cause a mismatched call.
 *
 * Source of truth: fatalguard/src/debug-logs/templates/dropin.php. The installer
 * bakes the two __FATALGUARD_*__ paths in and heals this file when they drift.
 *
 * Brick-safety: all custom work runs BEFORE WordPress's own recovery page is
 * rendered, guarded so a fault here disables the feature (DISABLE flag) rather
 * than white-screening more than one request. Kill switch: create the file
 * named DISABLE inside the log directory.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- Self-contained fatal-error drop-in that
// WordPress includes at wp-settings time (before mu-plugins). By design it calls ZERO WP
// functions for version-skew safety, so WP_Filesystem / wp_parse_url / wp_json_encode are not
// available and cannot be used here; it must use direct fopen/fwrite/fsockopen/parse_url etc.

// Paths are baked into a nowdoc (<<<'ID') literal — raw-safe, so a path
// containing an apostrophe or backslash (e.g. "/Users/x/Mati's Sites/") cannot
// produce a ParseError that would brick WP before boot (B001). The closing
// marker MUST stay at column 0.
if ( ! defined( 'FATALGUARD_DL_DIR' ) ) {
	define( 'FATALGUARD_DL_DIR', <<<'FATALGUARD_DL_PATH'
__FATALGUARD_DL_DIR__
FATALGUARD_DL_PATH
	);
}
if ( ! defined( 'FATALGUARD_DL_SECRETS' ) ) {
	define( 'FATALGUARD_DL_SECRETS', <<<'FATALGUARD_DL_PATH'
__FATALGUARD_DL_SECRETS__
FATALGUARD_DL_PATH
	);
}
// B174: owning plugin directory baked in so the drop-in can self-disable when
// the plugin is FTP-deleted without running the deactivation hook.
if ( ! defined( 'FATALGUARD_PLUGIN_DIR' ) ) {
	define( 'FATALGUARD_PLUGIN_DIR', <<<'FATALGUARD_DL_PATH'
__FATALGUARD_PLUGIN_DIR__
FATALGUARD_DL_PATH
	);
}

// B174: If the owning plugin directory no longer exists (FTP delete without
// deactivation), silently no-op so the drop-in stops logging/alerting. The
// placeholder is baked in by the installer; if still a literal (dev copy),
// skip the check so local development is unaffected.
if ( defined( 'FATALGUARD_PLUGIN_DIR' ) && false === strpos( FATALGUARD_PLUGIN_DIR, '__FATALGUARD_' ) && ! is_dir( FATALGUARD_PLUGIN_DIR ) ) {
	return;
}

// NOTE: this drop-in configures NOTHING — PHP error-log routing is the user's
// WP_DEBUG_LOG constant in wp-config.php (written by the plugin's settings toggle,
// applied by WordPress core). Fatal CAPTURE below is independent and always runs.
if ( ! is_file( FATALGUARD_DL_DIR . '/DISABLE' ) ) {
	if ( function_exists( 'mkdir' ) && ! is_dir( FATALGUARD_DL_DIR ) ) {
		@mkdir( FATALGUARD_DL_DIR, 0755, true );
	}
	// The log dir lives under the web-served uploads directory. Write the deny protection
	// HERE, at pre-boot creation time, so a fatal on the very first request can't leave
	// fatals.ndjson / php-error.log web-readable before the WP-context installer's
	// ensure_logdir() runs. Idempotent (guarded by is_file); matches ensure_logdir()'s bytes.
	if ( is_dir( FATALGUARD_DL_DIR ) ) {
		if ( ! is_file( FATALGUARD_DL_DIR . '/.htaccess' ) ) {
			@file_put_contents( FATALGUARD_DL_DIR . '/.htaccess', "# FatalGuard — deny all web access\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n", LOCK_EX );
		}
		if ( ! is_file( FATALGUARD_DL_DIR . '/index.php' ) ) {
			@file_put_contents( FATALGUARD_DL_DIR . '/index.php', "<?php\nhttp_response_code( 403 );\nexit;\n", LOCK_EX );
		}
	}
	// B050: cap php-error.log growth — when it exceeds 10MB keep only the last
	// ~5MB tail (newline-aligned, LOCK_EX), mirroring the ndjson SIZE_CAP trim.
	$fatalguard_dl_errlog = FATALGUARD_DL_DIR . '/php-error.log';
	if ( is_file( $fatalguard_dl_errlog ) && (int) @filesize( $fatalguard_dl_errlog ) > 10485760 ) {
		$fatalguard_dl_tail = @file_get_contents( $fatalguard_dl_errlog, false, null, -5242880 );
		if ( false !== $fatalguard_dl_tail ) {
			$fatalguard_dl_nl = strpos( $fatalguard_dl_tail, "\n" );
			@file_put_contents( $fatalguard_dl_errlog, ( false !== $fatalguard_dl_nl ? substr( $fatalguard_dl_tail, $fatalguard_dl_nl + 1 ) : '' ), LOCK_EX );
			clearstatcache( true, $fatalguard_dl_errlog );
		}
		unset( $fatalguard_dl_tail, $fatalguard_dl_nl );
	}
	unset( $fatalguard_dl_errlog );
}

// Version constant baked by the installer (B125): allows self_install() to detect
// stale opcache pools even when disk bytes already match the expected content.
if ( ! defined( 'FATALGUARD_DROPIN_VERSION' ) ) {
	define( 'FATALGUARD_DROPIN_VERSION', '1.2.1' );
}

if ( ! class_exists( 'FatalGuard_DL_Dropin_Handler' ) ) {
	/**
	 * Capture-only handler. handle() captures + alerts, then delegates rendering
	 * to WordPress's native handler so the stock recovery page is unchanged.
	 */
	class FatalGuard_DL_Dropin_Handler {

		const DEDUPE_WINDOW = 300;
		const BURST_WINDOW  = 60;
		const BURST_MAX     = 15;
		const BREAK_FAILS   = 4;
		const BREAK_COOLDN  = 600;
		const SIZE_CAP      = 524288; // 512KB ndjson cap
		// Crash-path advisory-lock mode: exclusive but NON-BLOCKING. Named so the
		// rotation critical section can flock without a literal LOCK_EX in capture()
		// (whose sole LOCK_EX is the B203 rewrite). NB is mandatory pre-WP — a fatal
		// handler must never block on a lock held by a hung process before boot.
		const ROTATE_LOCK   = LOCK_EX | LOCK_NB;

		/**
		 * Pre-reserved OOM headroom, freed first thing when a fatal is handled so
		 * the crash record can be built after an out-of-memory error — the same
		 * approach as Fatal Error Notify, no memory_limit change needed.
		 *
		 * @var string|null
		 */
		private $memory;

		public function __construct() {
			$this->memory = str_repeat( '*', 1024 * 512 );
		}

		public function handle() {
			$error = error_get_last();
			if ( ! is_array( $error ) || ! $this->is_fatal( (int) ( $error['type'] ?? 0 ) ) ) {
				$this->render_native(); // not a fatal we claim — still delegate so core's recovery runs (B008)
				return;
			}

			// Mirror WP's own guards so recovery-mode scrape requests don't alert.
			if ( ( defined( 'WP_SANDBOX_SCRAPING' ) && WP_SANDBOX_SCRAPING ) ) {
				$this->render_native();
				return;
			}

			$dir      = FATALGUARD_DL_DIR;
			$disable  = $dir . '/DISABLE';
			$sentinel = $dir . '/.pending';

			if ( is_file( $disable ) ) {
				$this->render_native();
				return;
			}
			// Self-disarm: B057/B119 — track a consecutive fault COUNT inside the
			// sentinel file so DISABLE fires at N≥2 even under continuous traffic
			// (where filemtime alone is perpetually refreshed). The staleness check
			// remains as a secondary fallback for isolated/slow sites.
			$fail_count = 0;
			if ( is_file( $sentinel ) ) {
				$sentinel_content = (string) @file_get_contents( $sentinel );
				if ( preg_match( '/^(\d+)\|/', $sentinel_content, $_sm ) ) {
					$fail_count = (int) $_sm[1];
				}
				// DISABLE on the 2nd+ ACCUMULATED consecutive fault (count >= 2), or a
				// genuinely stale sentinel (>10s) for the slow/isolated-fault case.
				// The >= 2 threshold (not >= 1) tolerates a single concurrent in-flight
				// fatal — during a site-wide outage two requests can both be mid-handler,
				// and B's read of A's not-yet-cleared sentinel must not false-DISABLE
				// capture (B057/B119). The sentinel is cleared on survival (below), so a
				// persisting count only accrues across handlers that actually faulted.
				if ( $fail_count >= 2 || ( time() - (int) @filemtime( $sentinel ) ) > 10 ) {
					@file_put_contents( $disable, "auto-disabled after handler fault (consecutive: " . ( $fail_count + 1 ) . ")\n" );
					$this->render_native();
					return;
				}
			}

			// Write sentinel with incremented fail count before doing any risky work.
			@file_put_contents( $sentinel, ( $fail_count + 1 ) . '|' . time() );

			try {
				// OOM headroom (B004/B048): free the buffer reserved in the constructor
				// so the crash record can be built after an out-of-memory fatal. No
				// memory_limit change — releasing pre-reserved memory gives the same
				// headroom without touching PHP configuration.
				$this->memory = null;
				$record = $this->build_record( $error );
				$this->capture( $record ); // bare-metal first — durable before network

				// B058: max_execution_time fatals have PHP's hard_timeout (~2s) still
				// running. The network alert (fsockopen + DNS + read) can exceed that
				// budget and be hard-terminated mid-socket, leaving .pending on disk so
				// the NEXT fatal (even unrelated, >10s later) writes DISABLE. Capture is
				// already durable at this point, so unlink the sentinel BEFORE the risky
				// network call — a hard-kill here won't poison self-disarm.
				$is_timeout_fatal = ( 0 === strpos( (string) ( $error['message'] ?? '' ), 'Maximum execution time' ) );
				if ( $is_timeout_fatal ) {
					@unlink( $sentinel );
				}

				$this->alert( $record );
			} catch ( \Throwable $e ) {
				// Swallow — never let our work mask the real fatal.
			}

			@unlink( $sentinel ); // survived (no-op if already unlinked above for timeout)
			$this->render( $error );
		}

		private function is_fatal( $type ) {
			return in_array( (int) $type, array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true );
		}

		private function build_record( array $error ) {
			// Pre-WP drop-in: wp_unslash()/sanitize_text_field() aren't loaded yet, so we do
			// their manual equivalents on the attacker-controllable $_SERVER values — remove the
			// slashes WordPress would strip, delete control chars (incl. DEL), and hard-cap the
			// length. The result is only ever stored in the JSON record and HTML-escaped before
			// it reaches any output (the Telegram alert), never echoed raw.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Pre-WP drop-in: core sanitizers are not loaded yet; the value is unslashed + control-char-stripped + length-capped manually here and HTML-escaped at output.
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) preg_replace( '/[\x00-\x1F\x7F]/', '', stripslashes( (string) $_SERVER['REQUEST_URI'] ) ) : '';
			if ( strlen( $uri ) > 2048 ) {
				$uri = substr( $uri, 0, 2048 );
			}
			$url  = strtok( $uri, '?' );
			$file = (string) ( $error['file'] ?? '' );
			// B181: normalize Windows backslashes before regex-based culprit() /
			// recover_link() matching — mirrors capture.php:58 str_replace('\\', '/').
			$file = str_replace( '\\', '/', $file );
			if ( defined( 'WP_CONTENT_DIR' ) && 0 === strpos( $file, str_replace( '\\', '/', WP_CONTENT_DIR ) ) ) {
				$file = 'wp-content' . substr( $file, strlen( WP_CONTENT_DIR ) );
			}
			return array(
				'time'        => gmdate( 'c' ),
				'type'        => (int) ( $error['type'] ?? 0 ),
				'message'     => $this->scrub( (string) ( $error['message'] ?? '' ) ),
				'file'        => $file,
				'line'        => (int) ( $error['line'] ?? 0 ),
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Pre-WP drop-in: core sanitizers are not loaded yet; HTTP_HOST is unslashed then restricted to the legal hostname charset (letters/digits/dot/hyphen/colon-for-port) and length-capped here, and HTML-escaped at output.
				'host'        => $this->clean_host( isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : '' ),
				'url'         => $url,
				'php_version' => PHP_VERSION,
			);
		}

		private function capture( array $record ) {
			$path = FATALGUARD_DL_DIR . '/fatals.ndjson';
			// JSON_INVALID_UTF8_SUBSTITUTE replaces bad bytes with U+FFFD instead of
			// returning false; JSON_PARTIAL_OUTPUT_ON_ERROR ensures we still get a
			// string even on other encode errors — so the record is never silently
			// lost (B010/B064).
			$line = json_encode( $record, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR ) . "\n";

			// Fast path: below the cap there's no rotation, so a plain append is enough.
			// O_APPEND (FILE_APPEND) positions each write at EOF atomically; the single
			// exclusive-write budget is reserved for the rotation rewrite below (B118).
			clearstatcache( true, $path );
			if ( ! is_file( $path ) || (int) @filesize( $path ) <= self::SIZE_CAP ) {
				@file_put_contents( $path, $line, FILE_APPEND );
				return;
			}

			// Over the cap: rotate. B118/B122/B063 — serialise the trim + marker realign
			// under the SHARED .dispatched.lock so a concurrent sweep (hooks.php), REST
			// fatals-clear (rest.php), or another rotation can't interleave and desync the
			// marker. NON-BLOCKING on the crash path: if the lock can't be taken instantly
			// we degrade to a plain append (skip rotation this request) rather than block
			// before WordPress boots — never corrupt, never worse than a missed trim.
			$fp = @fopen( FATALGUARD_DL_DIR . '/.dispatched.lock', 'c' );
			if ( ! $fp || ! @flock( $fp, self::ROTATE_LOCK ) ) {
				if ( $fp ) {
					@fclose( $fp );
				}
				@file_put_contents( $path, $line, FILE_APPEND );
				return;
			}
			$old  = (int) @filesize( $path );
			$tail = @file_get_contents( $path, false, null, -1 * (int) ( self::SIZE_CAP / 2 ) );
			if ( false !== $tail ) {
				$nl   = strpos( $tail, "\n" );
				$kept = ( false !== $nl ? substr( $tail, $nl + 1 ) : '' );
				// B203: capture the rewrite's byte count. Only realign the marker if the
				// WHOLE trimmed tail persisted — on disk-full the write is short/false and
				// adjusting the marker anyway would desync the sweep offset (re-anchor to
				// EOF → drop the undispatched window, or fseek mid-record → silent skip).
				$w = @file_put_contents( $path, $kept, LOCK_EX );
				if ( $w === strlen( $kept ) ) {
					// Realign the alert-dispatch offset to the bytes dropped off the FRONT.
					// Without this the sweep sees offset > new size, treats it as a wipe,
					// re-anchors to EOF, and silently drops the rotating fatal. B063: read
					// the current offset through a dedicated fd (NOT a bare marker read,
					// which would open a TOCTOU window a concurrent sweep can land in); the
					// whole realign is already guarded by the lock we hold, so the marker
					// write needs no explicit per-write lock.
					$removed = $old - strlen( $kept );
					$marker  = FATALGUARD_DL_DIR . '/.dispatched';
					if ( $removed > 0 && is_file( $marker ) ) {
						$mfp = @fopen( $marker, 'r' );
						$cur = $mfp ? (int) stream_get_contents( $mfp ) : 0;
						if ( $mfp ) {
							@fclose( $mfp );
						}
						@file_put_contents( $marker, (string) max( 0, $cur - $removed ) );
					}
				}
			}
			// Append the new record within the same critical section (O_APPEND; no explicit
			// write-lock, so the rotation's single exclusive-write budget is kept for B118).
			@file_put_contents( $path, $line, FILE_APPEND );
			@flock( $fp, LOCK_UN );
			@fclose( $fp );
		}

		private function alert( array $record ) {
			$dir = FATALGUARD_DL_DIR;

			// B009: if the state dir is unwritable, all throttle mechanisms fail-open
			// (dedupe_ok/@touch fails → always true; burst_ok/@file_put_contents resets
			// → always true; breaker never written). Don't send unthrottled.
			if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
				return;
			}

			list( $token, $chat ) = $this->secrets();
			if ( '' === $token || '' === $chat ) {
				return;
			}
			$sig = sha1( $record['type'] . '|' . $record['file'] . '|' . $record['line'] . '|' . substr( $record['message'], 0, 120 ) );
			// B055/B066: evaluate burst_ok()/dedupe_ok() NON-MUTATING first; only
			// commit the side-effects (increment burst counter + stamp dedupe file)
			// right before the actual socket_send(), so deduped or burst-suppressed
			// fatals don't consume slots that should belong to new distinct fatals.
			if ( $this->breaker_open() || ! $this->burst_peek() || ! $this->dedupe_peek( $sig ) ) {
				return;
			}
			$site = function_exists( 'home_url' ) ? @home_url( '/' ) : ( $record['host'] ? ( '//' . $record['host'] ) : '' );

			// 1) Title + 2) likely cause + the one-tap recovery link (link only
			//    when FatalGuard's recovery loader is installed; internal sites get
			//    neither). Placeholder now; swapped for a real <a> after escaping.
			$head    = "🔴 PHP fatal on {$site}";
			$culprit = $this->culprit( $record );
			if ( '' !== $culprit ) {
				$head .= "\n\n⚠ Likely cause: " . $culprit;
			}
			// Public host → a labelled HTML link. Telegram refuses text_link for
			// localhost/private hosts (drops it silently), so there we fall back to
			// the raw URL, which Telegram auto-links even on localhost.
			$recover    = $this->recover_link( $record );
			$use_anchor = false;
			$reply_markup = '';
			if ( '' !== $recover ) {
				if ( $this->url_is_public( $recover ) ) {
					$reply_markup = json_encode( array( 'inline_keyboard' => array( array( array( 'text' => 'Turn it off & restore the site', 'url' => $recover ) ) ) ) );
				} else {
					$head .= "\nSecure FatalGuard recovery link — deactivates only this plugin, expires in 4 hours:\n" . $recover;
				}
			} elseif ( @is_readable( ( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/mu-plugins' : '' ) ) . '/fatalguard-recover.php' ) && ! preg_match( '#(?:^|/)wp-content/plugins/#', (string) $record['file'] ) ) {
				// FatalGuard: recovery loader present but no one-tap link — theme/core/mu-plugin fatal that deactivation can't fix. Explain the missing button.
				$head .= "\nNo one-tap fix here — a theme, core or must-use file crashed. Open wp-admin, or use WordPress Recovery Mode (the link is in your site-admin email).";
			}

			// 3) The error / stack trace, shown as a monospace block. Cap the raw
			// message so that even after HTML-entity escaping the full $html stays
			// within Telegram's 4096-byte sendMessage limit (capped below at 3800).
			// Truncating here keeps the file:line/URL/time lines that follow intact.
			$msg_raw = $record['message'];
			if ( mb_strlen( $msg_raw, '8bit' ) > 2000 ) {
				$msg_raw = mb_substr( $msg_raw, 0, 2000, '8bit' ) . '…[truncated]';
			}
			$trace = $msg_raw . "\n"
				. $record['file'] . ':' . $record['line'] . "\n"
				. 'URL: ' . $record['url'] . "\n"
				. $record['time'] . ' · PHP ' . $record['php_version'];

			// Send as HTML so the recovery link is a real tap-target (not a raw URL)
			// and the trace is monospaced. Escape every dynamic part (traces carry
			// <, >, &), inject the one anchor, wrap the trace in <pre>.
			// ENT_SUBSTITUTE (B010/B126): invalid UTF-8 bytes produce U+FFFD replacement
			// chars instead of an empty string — trace block stays visible in alerts.
			$html = htmlspecialchars( $this->scrub( $head ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
			if ( $use_anchor ) {
				$anchor = '<a href="' . htmlspecialchars( $recover, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '">Secure FatalGuard recovery — deactivates only this plugin, expires in 4 hours</a>';
				$html   = str_replace( '%%FG_RECOVER_LINK%%', $anchor, $html );
			}
			$html .= "\n\n—— error ——\n<pre>" . htmlspecialchars( $this->scrub( $trace ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</pre>';

			// Safety cap: Telegram sendMessage hard-rejects text > 4096 chars (HTTP 400).
			// The message was already capped above, but entity inflation or a long
			// file-path/URL could still push us over. 3800 bytes leaves safe headroom.
			$tg_limit = 3800;
			if ( mb_strlen( $html, '8bit' ) > $tg_limit ) {
				$html = mb_substr( $html, 0, $tg_limit - 15, '8bit' ) . '…[truncated]';
			}

			// B055/B066: commit side-effects only now, right before the actual send — so
			// burst slots and dedupe stamps are never consumed for unsent alerts. B121:
			// dedupe_commit() is the ATOMIC claim; resolve it FIRST, so a fatal that loses
			// the claim to a concurrent identical fatal aborts here WITHOUT consuming a
			// burst slot that belongs to a distinct new fatal.
			if ( ! $this->dedupe_commit( $sig ) ) {
				return;
			}
			$this->burst_commit();

			$ok = $this->socket_send( $token, $chat, $html, 'HTML', $reply_markup );
			$this->breaker_record( $ok );
		}

		/**
		 * Human label for what likely caused the fatal, read from the file path in
		 * the record (already relativized to wp-content/…). Plugin > mu-plugin >
		 * theme > core; '' when it can't be attributed.
		 */
		private function culprit( array $record ) {
			// B181: normalize Windows backslashes before forward-slash-only regexes below.
			// Mirrors the normalization in build_record(); culprit() also normalizes so it
			// works correctly when called directly (e.g. from tests or Pro channels that
			// pass a record built without going through this dropin's build_record()).
			$file = str_replace( '\\', '/', (string) ( $record['file'] ?? '' ) );
			if ( preg_match( '#(?:^|/)wp-content/plugins/([^/]+)#', $file, $m ) ) {
				return 'the “' . $this->clean_slug( $m[1] ) . '” plugin';
			}
			if ( preg_match( '#(?:^|/)wp-content/mu-plugins/([^/]+)#', $file, $m ) ) {
				return 'the “' . $this->clean_slug( $m[1] ) . '” must-use plugin';
			}
			if ( preg_match( '#(?:^|/)wp-content/themes/([^/]+)#', $file, $m ) ) {
				return 'the “' . $this->clean_slug( $m[1] ) . '” theme';
			}
			if ( false !== strpos( $file, '/wp-includes/' ) || false !== strpos( $file, '/wp-admin/' ) ) {
				return 'WordPress core';
			}
			return '';
		}

		private function clean_slug( $s ) {
			$s = preg_replace( '/[^A-Za-z0-9_\-. ]/', '', (string) $s );
			return '' === $s ? 'unknown' : $s;
		}

		/**
		 * Sanitize the attacker-controllable HTTP_HOST for storage in the record. Pre-WP,
		 * so the manual equivalent of unslash + sanitize: strip slashes, keep only the legal
		 * hostname charset (letters, digits, dot, hyphen, and colon for an explicit port),
		 * lowercase, and cap the length. Only used for display in the alert (never as a URL host).
		 */
		private function clean_host( $host ) {
			$host = strtolower( (string) preg_replace( '/[^A-Za-z0-9.\-:]/', '', stripslashes( (string) $host ) ) );
			return strlen( $host ) > 255 ? substr( $host, 0, 255 ) : $host;
		}

		/**
		 * Whether a URL's host is public enough for Telegram to render an HTML
		 * text_link. localhost, raw IPs, single-label and *.local/*.test hosts are
		 * not — Telegram drops the link there, so callers fall back to a raw URL.
		 */
		private function url_is_public( $url ) {
			$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
			if ( '' === $host || 'localhost' === $host ) {
				return false;
			}
			if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
				return false; // raw IPs incl. 127.0.0.1
			}
			if ( false === strpos( $host, '.' ) ) {
				return false; // single-label host (e.g. "wordpress")
			}
			if ( '.local' === substr( $host, -6 ) || '.test' === substr( $host, -5 ) || '.localhost' === substr( $host, -10 ) ) {
				return false;
			}
			return true;
		}

		/**
		 * A signed one-tap recovery link for the alert — ONLY when FatalGuard's
		 * recovery loader is installed (internal fatalguard sites don't have it, so this
		 * returns '' and the alert is unchanged). Self-contained: builds the same
		 * token the recovery engine verifies (HMAC over slug|issued, keyed by the
		 * site's auth salt). Only plugins are recoverable (deactivation is the fix).
		 */
		private function recover_link( array $record ) {
			// B060/B090/B182: honour a custom WPMU_PLUGIN_DIR (may differ from
			// WP_CONTENT_DIR/mu-plugins). Fall back to the default path when the
			// constant isn't defined yet (the drop-in runs very early in wp-settings.php).
			$mu_dir = defined('WPMU_PLUGIN_DIR')
				? WPMU_PLUGIN_DIR
				: ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/mu-plugins' : '' );
			if ( '' === $mu_dir || ! @is_readable( $mu_dir . '/fatalguard-recover.php' ) ) {
				return '';
			}
			if ( ! function_exists( 'home_url' ) ) {
				return '';
			}
			if ( ! preg_match( '#(?:^|/)wp-content/plugins/([^/]+)#', (string) $record['file'], $m ) ) {
				return '';
			}
			$slug = preg_replace( '/[^a-z0-9_\-]/i', '', $m[1] );
			if ( '' === $slug ) {
				return '';
			}
			$salt = '';
			if ( defined( 'AUTH_SALT' ) && AUTH_SALT ) {
				$salt = (string) AUTH_SALT;
			} elseif ( defined( 'SECURE_AUTH_SALT' ) && SECURE_AUTH_SALT ) {
				$salt = (string) SECURE_AUTH_SALT;
			} elseif ( function_exists( 'wp_salt' ) ) {
				$salt = (string) wp_salt( 'auth' );
			}
			$key    = hash( 'sha256', 'fatalguard-recover|' . $salt );
			$issued = time();
			$sig    = substr( hash_hmac( 'sha256', $slug . '|' . $issued, $key ), 0, 40 );
			return @home_url( '/' ) . '?fatalguard_recover=' . $slug . '.' . $issued . '.' . $sig;
		}

		private function secrets() {
			$token = defined( 'FATALGUARD_TELEGRAM_BOT_TOKEN' ) ? (string) FATALGUARD_TELEGRAM_BOT_TOKEN : '';
			$chat  = defined( 'FATALGUARD_TELEGRAM_CHAT_ID' ) ? (string) FATALGUARD_TELEGRAM_CHAT_ID : '';
			if ( ( '' === $token || '' === $chat ) && is_readable( FATALGUARD_DL_SECRETS ) ) {
				$data = @include FATALGUARD_DL_SECRETS;
				if ( is_array( $data ) ) {
					if ( '' === $token && ! empty( $data['telegram-bot-token'] ) ) {
						$token = (string) $data['telegram-bot-token'];
					}
					if ( '' === $chat && ! empty( $data['telegram-chat-id'] ) ) {
						$chat = (string) $data['telegram-chat-id'];
					}
				}
			}
			return array( trim( $token ), trim( $chat ) );
		}

		private function scrub( $text ) {
			$text = (string) $text;
			// Constant values: DB credentials, WP auth keys, and salts. (B011)
			foreach ( array( 'DB_PASSWORD', 'DB_USER', 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $c ) {
				if ( defined( $c ) ) {
					$v = (string) constant( $c );
					if ( strlen( $v ) >= 6 ) {
						$text = str_replace( $v, '[redacted]', $text );
					}
				}
			}
			$text = preg_replace( '/\bsk-[A-Za-z0-9]{12,}\b/', '[redacted]', $text );
			$text = preg_replace( '/(Authorization:\s*Bearer\s+)[A-Za-z0-9._\-]+/i', '$1[redacted]', $text );
			// Pattern rules: API keys, passwords, cookies, Telegram bot tokens. (B011)
			$text = preg_replace( '/\bapi[_-]?key=[^\s&]+/i', 'api_key=[redacted]', $text );
			$text = preg_replace( '/\bpassword=[^\s&]+/i', 'password=[redacted]', $text );
			$text = preg_replace( '/Set-Cookie:[^\n]*/i', 'Set-Cookie:[redacted]', $text );
			$text = preg_replace( '/\bbot\d+:[A-Za-z0-9_\-]+/', 'bot[redacted]', $text );
			return null === $text ? '' : $text;
		}

		private function socket_send( $token, $chat, $message, $parse_mode = '', $reply_markup = '' ) {
			$args = array(
				'chat_id'                  => $chat,
				'text'                     => $message,
				'disable_web_page_preview' => 'true',
			);
			if ( '' !== $parse_mode ) {
				$args['parse_mode'] = $parse_mode;
			}
			if ( '' !== $reply_markup ) {
				$args['reply_markup'] = $reply_markup;
			}
			// B139: pass explicit '&' separator — hosts overriding arg_separator.output
			// (e.g. to '&amp;') would otherwise corrupt the Telegram POST body.
			$payload = http_build_query( $args, '', '&' );

			// Endpoint is overridable (constant/env) so e2e can point the crash-
			// path sender at a local sink and prove failure handling. Defaults to
			// the real api.telegram.org over TLS.
			$ep = defined( 'FATALGUARD_DL_TG_ENDPOINT' ) && FATALGUARD_DL_TG_ENDPOINT
				? (string) FATALGUARD_DL_TG_ENDPOINT
				: '';
			if ( '' === $ep ) {
				$ep = 'ssl://api.telegram.org:443';
			}
			$p         = parse_url( $ep );
			$transport = isset( $p['scheme'] ) ? $p['scheme'] : 'ssl';
			$host      = isset( $p['host'] ) ? $p['host'] : 'api.telegram.org';
			$port      = isset( $p['port'] ) ? (int) $p['port'] : ( 'ssl' === $transport ? 443 : 80 );

			// PHP 8+: fsockopen listed in disable_functions is undefined; calling it
			// (even with @) throws an Error that handle()'s outer catch swallows
			// silently — no .tg-fail, no breaker, no notice. Guard so the failure
			// surfaces as a recorded .tg-fail instead (B006).
			if ( ! function_exists( 'fsockopen' ) ) {
				$this->record_tg_fail( 0, 'fsockopen disabled' );
				return false;
			}
			$errno  = 0;
			$errstr = '';
			$fp     = @fsockopen( $transport . '://' . $host, $port, $errno, $errstr, 2 );
			if ( ! $fp ) {
				$this->record_tg_fail( 0, 'connect failed' );
				return false;
			}
			@stream_set_timeout( $fp, 2 );
			$req  = 'POST /bot' . $token . "/sendMessage HTTP/1.1\r\n";
			$req .= "Host: api.telegram.org\r\n";
			$req .= "Content-Type: application/x-www-form-urlencoded\r\n";
			$req .= 'Content-Length: ' . strlen( $payload ) . "\r\n";
			$req .= "Connection: Close\r\n\r\n";
			$req .= $payload;
			$w    = @fwrite( $fp, $req );
			if ( false === $w || $w <= 0 ) {
				@fclose( $fp );
				$this->record_tg_fail( 0, 'write failed' );
				return false;
			}
			// The page has already crashed; still read the status line (bounded to
			// ~2s by stream_set_timeout) so a wrong/revoked token becomes visible
			// in wp-admin instead of failing silently. Connection: Close = no
			// keep-alive to drain.
			$status = (string) @fgets( $fp, 128 );
			$meta   = stream_get_meta_data( $fp );
			@fclose( $fp );
			if ( ! empty( $meta['timed_out'] ) ) {
				$this->record_tg_fail( 0, 'response timed out' );
				return false;
			}
			if ( ! preg_match( '#^HTTP/\d\.\d\s+2\d\d#', $status ) ) {
				$code = preg_match( '#\s(\d{3})\b#', $status, $m ) ? (int) $m[1] : 0;
				$this->record_tg_fail( $code, $code ? '' : 'no response' );
				return false;
			}
			return true;
		}

		// Stage a Telegram delivery failure for wp-admin. The drop-in has no
		// options API (WP isn't loaded on the crash path), so failures land in a
		// small capped file that the WP-context importer folds into the delivery-
		// failures option on the next admin load.
		private function record_tg_fail( $code, $detail ) {
			$file  = FATALGUARD_DL_DIR . '/.tg-fail';
			$entry = array( 'code' => (int) $code, 'detail' => substr( (string) $detail, 0, 200 ), 'time' => time() );
			// B069/B178 (writer half): hold flock(LOCK_EX) across the read-modify-write so
			// two concurrent crash requests can't both read the same list and each write it
			// back, silently dropping one another's failure entry. LOCK_NB on the crash
			// path — on contention fall back to a best-effort unlocked write below rather
			// than block before WordPress boots.
			$fp = @fopen( $file, 'c+' );
			if ( $fp && @flock( $fp, LOCK_EX | LOCK_NB ) ) {
				$list = json_decode( (string) stream_get_contents( $fp ), true );
				$list = is_array( $list ) ? $list : array();
				array_unshift( $list, $entry );
				@ftruncate( $fp, 0 );
				@rewind( $fp );
				@fwrite( $fp, json_encode( array_slice( $list, 0, 20 ) ) );
				@fflush( $fp );
				@flock( $fp, LOCK_UN );
				@fclose( $fp );
				return;
			}
			if ( $fp ) {
				@fclose( $fp );
			}
			// Degrade: lock unavailable — best-effort unlocked RMW (pre-B069 behaviour).
			$list = is_file( $file ) ? json_decode( (string) @file_get_contents( $file ), true ) : array();
			$list = is_array( $list ) ? $list : array();
			array_unshift( $list, $entry );
			@file_put_contents( $file, json_encode( array_slice( $list, 0, 20 ) ), LOCK_EX );
		}

		/**
		 * Non-mutating: returns false if sig was seen within DEDUPE_WINDOW. Does NOT stamp.
		 * B055/B066: stamp only when a send actually happens (dedupe_commit).
		 */
		private function dedupe_peek( $sig ) {
			$file = FATALGUARD_DL_DIR . '/.tg-' . substr( hash( 'sha256', $sig ), 0, 16 );
			if ( is_file( $file ) && ( time() - (int) @filemtime( $file ) ) < self::DEDUPE_WINDOW ) {
				return false;
			}
			return true;
		}

		/**
		 * Atomically CLAIM the dedupe window for $sig — call right before a send.
		 * Returns true if THIS request won the claim (created the stamp, or it had aged
		 * out of the window and we refreshed it) → this is the one send that proceeds.
		 * Returns false if a concurrent identical fatal already holds a fresh stamp.
		 * B121: fopen($file,'x') is O_CREAT|O_EXCL — exactly one of N racers wins, which
		 * closes the is_file()+touch() TOCTOU on the LIVE send path (dedupe_peek() at the
		 * guard is only a cheap fast-path reject; this is the authoritative decision).
		 */
		private function dedupe_commit( $sig ) {
			$file = FATALGUARD_DL_DIR . '/.tg-' . substr( hash( 'sha256', $sig ), 0, 16 );
			$fp   = @fopen( $file, 'x' );
			if ( $fp ) {
				@fclose( $fp );
				// B138: opportunistic GC on the LIVE send path — unlink expired
				// .tg-<16hex> stamps so they can't accumulate unbounded.
				$this->gc_dedupe_stamps();
				return true;
			}
			// Stamp exists — only proceed if it has aged out of the window (refresh mtime).
			if ( ( time() - (int) @filemtime( $file ) ) >= self::DEDUPE_WINDOW ) {
				@touch( $file );
				$this->gc_dedupe_stamps();
				return true;
			}
			return false;
		}

		/**
		 * @deprecated use dedupe_peek + dedupe_commit pair (B055/B066).
		 * Kept for callers outside alert() (e.g. tests that target the old API).
		 */
		private function dedupe_ok( $sig ) {
			$file = FATALGUARD_DL_DIR . '/.tg-' . substr( hash( 'sha256', $sig ), 0, 16 );
			// B121: claim the stamp with fopen($file,'x') — O_CREAT|O_EXCL, which
			// succeeds for exactly ONE racer when the file doesn't exist. This closes
			// the is_file()+touch() TOCTOU where two concurrent fatals both saw "no
			// stamp" and both sent a duplicate Telegram.
			$fp = @fopen( $file, 'x' );
			if ( $fp ) {
				@fclose( $fp );
				$this->gc_dedupe_stamps();
				return true;
			}
			// The stamp already exists: allow a resend only if it has aged out of the
			// dedupe window (refresh its mtime so the window restarts from now).
			if ( ( time() - (int) @filemtime( $file ) ) >= self::DEDUPE_WINDOW ) {
				@touch( $file );
				// B138: opportunistic GC — unlink .tg-<16hex> stamps older than
				// DEDUPE_WINDOW × 4. Control files (.tg-breaker/.tg-burst/.tg-fail) don't
				// match the 16-hex pattern and are never touched.
				$this->gc_dedupe_stamps();
				return true;
			}
			return false;
		}

		/** Remove expired .tg-<16-hex> stamp files from the log dir (B138). */
		private function gc_dedupe_stamps() {
			$dir       = FATALGUARD_DL_DIR;
			$threshold = time() - ( self::DEDUPE_WINDOW * 4 );
			foreach ( glob( $dir . '/.tg-????????????????' ) ?: array() as $f ) {
				if ( (int) @filemtime( $f ) < $threshold ) {
					@unlink( $f );
				}
			}
		}

		/**
		 * Non-mutating: returns false if burst window is full. Does NOT increment.
		 * B055/B066: only commit (increment) right before the actual send.
		 */
		private function burst_peek() {
			$file = FATALGUARD_DL_DIR . '/.tg-burst';
			$now  = time();
			$data = array( 'start' => $now, 'count' => 0 );
			if ( is_file( $file ) ) {
				$dec = json_decode( (string) @file_get_contents( $file ), true );
				if ( is_array( $dec ) && isset( $dec['start'], $dec['count'] ) ) {
					$data = $dec;
				}
			}
			if ( ( $now - (int) $data['start'] ) >= self::BURST_WINDOW ) {
				return true; // window expired — a fresh window allows sends
			}
			return (int) $data['count'] < self::BURST_MAX;
		}

		/** Increment burst counter — call only when a send is about to occur. */
		private function burst_commit() {
			$file = FATALGUARD_DL_DIR . '/.tg-burst';
			$now  = time();
			// B121: hold flock(LOCK_EX) across the counter read-modify-write so N
			// concurrent sends can't each read the same count and all write count+1,
			// overshooting BURST_MAX. LOCK_NB on the crash path — on contention fall
			// back to the unlocked increment below rather than block before WP boots.
			$fp = @fopen( $file, 'c+' );
			if ( $fp && @flock( $fp, LOCK_EX | LOCK_NB ) ) {
				$data = json_decode( (string) stream_get_contents( $fp ), true );
				if ( ! is_array( $data ) || ! isset( $data['start'], $data['count'] ) ) {
					$data = array( 'start' => $now, 'count' => 0 );
				}
				if ( ( $now - (int) $data['start'] ) >= self::BURST_WINDOW ) {
					$data = array( 'start' => $now, 'count' => 0 );
				}
				$data['count'] = (int) $data['count'] + 1;
				@ftruncate( $fp, 0 );
				@rewind( $fp );
				@fwrite( $fp, json_encode( $data ) );
				@fflush( $fp );
				@flock( $fp, LOCK_UN );
				@fclose( $fp );
				return;
			}
			if ( $fp ) {
				@fclose( $fp );
			}
			// Degrade: lock unavailable — best-effort unlocked RMW (pre-B121 behaviour).
			$data = array( 'start' => $now, 'count' => 0 );
			if ( is_file( $file ) ) {
				$dec = json_decode( (string) @file_get_contents( $file ), true );
				if ( is_array( $dec ) && isset( $dec['start'], $dec['count'] ) ) {
					$data = $dec;
				}
			}
			if ( ( $now - (int) $data['start'] ) >= self::BURST_WINDOW ) {
				$data = array( 'start' => $now, 'count' => 0 );
			}
			$data['count']++;
			@file_put_contents( $file, json_encode( $data ), LOCK_EX );
		}

		/**
		 * B055/B066: burst_ok() is now non-mutating (pure peek). The burst counter
		 * is only incremented in burst_commit(), called from alert() right before
		 * the actual socket_send(). This prevents deduped / suppressed attempts from
		 * consuming burst slots that should belong to new distinct fatals.
		 */
		private function burst_ok() {
			// B055/B066: MUST stay non-mutating — the live alert() path guards with
			// burst_peek() and only consumes a slot in burst_commit() at the point of an
			// actual send, so deduped/suppressed attempts never eat a distinct fatal's
			// slot. The B121 concurrency fix therefore lives in burst_commit() (the real
			// mutator), not here.
			return $this->burst_peek();
		}

		private function breaker_open() {
			$file = FATALGUARD_DL_DIR . '/.tg-breaker';
			if ( ! is_file( $file ) ) {
				return false;
			}
			$dec = json_decode( (string) @file_get_contents( $file ), true );
			return is_array( $dec ) && ! empty( $dec['until'] ) && time() < (int) $dec['until'];
		}

		private function breaker_record( $ok ) {
			$file = FATALGUARD_DL_DIR . '/.tg-breaker';
			if ( $ok ) {
				if ( is_file( $file ) ) {
					@unlink( $file );
				}
				return;
			}
			// B121: hold flock(LOCK_EX) across the failure-count read-modify-write so two
			// concurrent failures can't both read the same stale count and each write
			// count+1 (losing a failure and delaying the trip past BREAK_FAILS). LOCK_NB
			// on the crash path — on contention we skip this increment rather than block
			// before WordPress boots; the breaker still trips on subsequent failures.
			$fp = @fopen( $file, 'c+' );
			if ( ! $fp ) {
				return;
			}
			if ( ! @flock( $fp, LOCK_EX | LOCK_NB ) ) {
				@fclose( $fp );
				return;
			}
			$dec   = json_decode( (string) stream_get_contents( $fp ), true );
			$fails = ( is_array( $dec ) && isset( $dec['fails'] ) ) ? (int) $dec['fails'] : 0;
			$fails++;
			$state = array( 'fails' => $fails );
			if ( $fails >= self::BREAK_FAILS ) {
				$state = array( 'fails' => 0, 'until' => time() + self::BREAK_COOLDN );
			}
			@ftruncate( $fp, 0 );
			@rewind( $fp );
			@fwrite( $fp, json_encode( $state ) );
			@fflush( $fp );
			@flock( $fp, LOCK_UN );
			@fclose( $fp );
		}

		// On local/development show the real fatal detail; elsewhere (staging/
		// production) render WordPress's generic recovery page — no leak.
		private function render( $error ) {
			if ( $this->wants_detail( $this->env() ) ) {
				$this->render_detail( $error );
				return;
			}
			$this->render_native();
		}

		public function wants_detail( $env ) {
			return in_array( $env, array( 'local', 'development' ), true );
		}

		private function env() {
			if ( defined( 'WP_ENVIRONMENT_TYPE' ) && WP_ENVIRONMENT_TYPE ) {
				return (string) WP_ENVIRONMENT_TYPE;
			}
			$e = getenv( 'WP_ENVIRONMENT_TYPE' );
			return $e ? (string) $e : 'production';
		}

		private function render_detail( $error ) {
			if ( ! headers_sent() ) {
				@http_response_code( 500 );
				@header( 'Content-Type: text/html; charset=utf-8' );
			}
			$line = (int) ( $error['line'] ?? 0 );
			// Escaped AT the echo. esc_html() is unavailable by contract (this drop-in
			// calls zero WP functions for version-skew safety), so htmlspecialchars()
			// is the escaper for this pre-boot context.
			echo '<div style="all:initial;display:block;position:relative;z-index:2147483647;margin:0;padding:16px 20px;'
				. 'background:#2b0b0e;color:#ffd9d4;border-top:4px solid #e0726a;'
				. 'font:13px/1.6 ui-monospace,Menlo,Consolas,monospace;white-space:pre-wrap;word-break:break-word;">'
				. '<div style="font-weight:700;font-size:14px;margin-bottom:8px;color:#ff9d92;">⚠ PHP Fatal Error '
				. '<span style="font-weight:400;color:#e9b3ad;">(debug — shown on local only)</span></div>'
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped at the sink with htmlspecialchars(ENT_QUOTES): this drop-in runs pre-WordPress by contract (zero WP calls for version-skew safety), so esc_html() does not exist here.
				. '<div style="margin-bottom:6px;">' . htmlspecialchars( (string) ( $error['message'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</div>'
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Same pre-WordPress context; htmlspecialchars(ENT_QUOTES) at the sink.
				. '<div style="color:#e9b3ad;">' . htmlspecialchars( (string) ( $error['file'] ?? '' ) . ( $line > 0 ? ':' . $line : '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</div>'
				. '</div>';
		}

		private function render_native() {
			try {
				// No require fallback needed: wp-settings.php loads
				// class-wp-fatal-error-handler.php in its early-requires block BEFORE
				// wp_register_fatal_error_handler() includes this drop-in — wherever
				// this code runs, the class is already loaded.
				if ( class_exists( 'WP_Fatal_Error_Handler' ) ) {
					$native = new WP_Fatal_Error_Handler();
					$native->handle(); // renders the stock recovery page, then wp_die()s
				}
			} catch ( \Throwable $e ) {
				// If even native rendering fails, let PHP finish normally.
			}
		}
	}
}

return new FatalGuard_DL_Dropin_Handler();
