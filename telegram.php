<?php
/**
 * Telegram alerting. Creds come from the settings UI (promoted to constants),
 * never read from env or theme files. The fatal-path send is non-blocking fsockopen (a slow
 * api.telegram.org must never delay or brick the page); file-based dedupe + a
 * burst cap + a circuit breaker stop a fatal loop from spamming, and work even
 * when the DB is down. The admin /test-telegram route is the only blocking send.
 *
 * @package FatalGuard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- Alerts are delivered over a raw
// (optionally TLS) socket via fsockopen()/fwrite() so a fatal can notify even when the HTTP
// API stack is unavailable; dedupe/breaker state uses direct marker files. WP_Filesystem
// cannot open sockets and is not loaded when a fatal fires this path.

const FATALGUARD_DL_TG_HOST                    = 'api.telegram.org';
const FATALGUARD_DL_TG_DEDUPE_WINDOW           = 300;   // seconds per identical signature
const FATALGUARD_DL_TG_BURST_WINDOW            = 60;    // rolling window for the global cap
const FATALGUARD_DL_TG_BURST_MAX               = 15;    // max sends per burst window
const FATALGUARD_DL_TG_BREAK_FAILS             = 4;     // consecutive failures before opening
const FATALGUARD_DL_TG_BREAK_COOLDN            = 600;   // breaker-open cooldown (seconds)
const FATALGUARD_DL_TG_HEALTH_NET_FAIL_THRESHOLD = 2;   // B142: consecutive network-fail probes before escalating

/**
 * The Telegram send endpoint as [ transport, host, port ]. Defaults to the real
 * api.telegram.org over TLS; overridable via the FATALGUARD_DL_TG_ENDPOINT constant
 * or env (e.g. "tcp://host.docker.internal:9443") so e2e can point the sender at
 * a local sink and assert real failure handling without hitting Telegram.
 *
 * @return array{0:string,1:string,2:int}
 */
function fatalguard_tg_endpoint(): array {
	$ep = defined( 'FATALGUARD_DL_TG_ENDPOINT' ) && FATALGUARD_DL_TG_ENDPOINT
		? (string) FATALGUARD_DL_TG_ENDPOINT
		: '';
	if ( '' === $ep ) {
		$ep = 'ssl://' . FATALGUARD_DL_TG_HOST . ':443';
	}
	$p         = wp_parse_url( $ep );
	$transport = isset( $p['scheme'] ) ? $p['scheme'] : 'ssl';
	$host      = isset( $p['host'] ) ? $p['host'] : FATALGUARD_DL_TG_HOST;
	$port      = isset( $p['port'] ) ? (int) $p['port'] : ( 'ssl' === $transport ? 443 : 80 );
	return array( $transport, $host, $port );
}

/** Path to the theme secrets file (normal context only). */
function fatalguard_secrets_path(): string {
	return ''; // FatalGuard: no theme secrets file — Telegram creds come from the settings UI (constants).
}

/**
 * Resolve Telegram creds: constant → env → secrets file → empty.
 *
 * @return array{bot_token:string,chat_id:string}
 */
function fatalguard_telegram_config(): array {
	static $cfg = null;
	if ( null !== $cfg ) {
		return $cfg;
	}
	$token = '';
	$chat  = '';

	if ( defined( 'FATALGUARD_TELEGRAM_BOT_TOKEN' ) ) {
		$token = (string) FATALGUARD_TELEGRAM_BOT_TOKEN;
	}
	if ( defined( 'FATALGUARD_TELEGRAM_CHAT_ID' ) ) {
		$chat = (string) FATALGUARD_TELEGRAM_CHAT_ID;
	}

	if ( '' === $token || '' === $chat ) {
		$path = fatalguard_secrets_path();
		if ( '' !== $path && is_readable( $path ) ) {
			$data = @include $path;
			if ( is_array( $data ) ) {
				if ( '' === $token && ! empty( $data['telegram-bot-token'] ) ) {
					$token = (string) $data['telegram-bot-token'];
				}
				if ( '' === $chat && ! empty( $data['telegram-chat-id'] ) ) {
					$chat = (string) $data['telegram-chat-id'];
				}
			}
		}
	}

	$cfg = array(
		'bot_token' => trim( $token ),
		'chat_id'   => trim( $chat ),
	);
	return $cfg;
}

/** True when both creds are present (alerting can fire). */
function fatalguard_telegram_configured(): bool {
	$cfg = fatalguard_telegram_config();
	return '' !== $cfg['bot_token'] && '' !== $cfg['chat_id'];
}

/**
 * Send a fatal alert with dedupe + burst-cap + breaker. Non-blocking by
 * default. Returns a small status array (never throws).
 *
 * @return array{sent:bool,reason:string}
 */
function fatalguard_telegram_alert( string $message, string $signature ): array {
	if ( fatalguard_is_disabled() ) {
		return array( 'sent' => false, 'reason' => 'disabled' );
	}
	if ( ! fatalguard_telegram_configured() ) {
		return array( 'sent' => false, 'reason' => 'not_configured' );
	}
	if ( fatalguard_tg_breaker_open() ) {
		return array( 'sent' => false, 'reason' => 'breaker_open' );
	}
	// Burst cap BEFORE dedupe: dedupe_ok() stamps the signature as a side-effect, so
	// checking it first would consume a distinct fatal's dedupe slot even when the
	// burst cap suppresses the send — blocking that fatal for the rest of the dedupe
	// window (~300s) after the burst clears. Order matters here.
	if ( ! fatalguard_tg_burst_ok() ) {
		return array( 'sent' => false, 'reason' => 'burst_capped' );
	}
	if ( ! fatalguard_tg_dedupe_ok( $signature ) ) {
		return array( 'sent' => false, 'reason' => 'deduped' );
	}

	$cfg = fatalguard_telegram_config();
	$ok  = fatalguard_tg_socket_send( $cfg['bot_token'], $cfg['chat_id'], $message );
	// B067: null = slow-response/unknown — the message was written to the socket
	// but the status-read timed out. Do NOT trip the breaker or record a failure;
	// the channel is probably working fine. Only act on a definitive bool result.
	if ( null !== $ok ) {
		fatalguard_tg_breaker_record( $ok );
		if ( ! $ok ) {
			fatalguard_record_alert_failure( 'Telegram', FATALGUARD_DL_TG_HOST, 'send failed (bad token, revoked bot, or network)' );
		} else {
			// B159: a successful REAL send is proof-of-life — clear stale failure
			// notices so a channel that has since recovered stops showing a red
			// 'alerts failing' state until the next weekly cron would clear it.
			fatalguard_clear_alert_failures( 'Telegram', 'all' );
		}
	}

	$sent   = true === $ok;
	$reason = true === $ok ? 'ok' : ( null === $ok ? 'unknown' : 'send_failed' );
	return array( 'sent' => $sent, 'reason' => $reason );
}

/**
 * SSL socket POST to Telegram sendMessage. Writes the request, then reads the
 * HTTP status line (bounded by a 2s timeout) and returns true only on a 2xx,
 * false on a definitive failure (connect/write error or 4xx), or null when the
 * write succeeded but reading the response timed out (B067: slow-response is
 * 'unknown', not a failure — must not trip the circuit breaker).
 *
 * @return bool|null true = sent OK, false = definitive failure, null = unknown/slow.
 */
function fatalguard_tg_socket_send( string $token, string $chat_id, string $message ): ?bool {
	// Telegram sendMessage hard-rejects text > 4096 chars (HTTP 400). Cap at 3800
	// so HTML-entity inflation and encoding overhead never push us over the limit.
	$tg_limit = 3800;
	if ( mb_strlen( $message, '8bit' ) > $tg_limit ) {
		$message = mb_substr( $message, 0, $tg_limit, '8bit' ) . '…[truncated]';
	}
	$payload = http_build_query(
		array(
			'chat_id'                  => $chat_id,
			'text'                     => $message,
			'disable_web_page_preview' => 'true',
		)
	);
	$path = '/bot' . $token . '/sendMessage';

	list( $transport, $host, $port ) = fatalguard_tg_endpoint();
	// On PHP 8+ a function in disable_functions is treated as undefined: calling
	// it (even with @) throws an uncaught Error. Guard before calling so a
	// hardened host records a failure instead of fataling the init sweep (B003/B006).
	if ( ! function_exists( 'fsockopen' ) ) {
		fatalguard_record_alert_failure( 'Telegram', FATALGUARD_DL_TG_HOST, 'fsockopen unavailable — host disabled it (disable_functions)' );
		return false;
	}
	$errno  = 0;
	$errstr = '';
	$fp     = @fsockopen( $transport . '://' . $host, $port, $errno, $errstr, 2 );
	if ( ! $fp ) {
		return false;
	}
	@stream_set_timeout( $fp, 2 );

	$request  = "POST {$path} HTTP/1.1\r\n";
	$request .= 'Host: ' . FATALGUARD_DL_TG_HOST . "\r\n";
	$request .= "Content-Type: application/x-www-form-urlencoded\r\n";
	$request .= 'Content-Length: ' . strlen( $payload ) . "\r\n";
	$request .= "Connection: Close\r\n\r\n";
	$request .= $payload;

	$written = @fwrite( $fp, $request );
	if ( false === $written || $written <= 0 ) {
		@fclose( $fp );
		return false;
	}
	// Read the HTTP status line so a wrong/revoked token (4xx) is the failure it
	// is: the breaker must trip and the failure must surface, never read as a
	// success. Bounded by stream_set_timeout() above.
	$status = (string) @fgets( $fp, 128 );
	$meta   = stream_get_meta_data( $fp );
	@fclose( $fp );
	if ( ! empty( $meta['timed_out'] ) ) {
		// B067: the write succeeded (message was delivered to Telegram's TCP stack)
		// but the STATUS READ timed out — outcome is unknown/slow, not a failure.
		// Returning null prevents the caller from tripping the circuit breaker.
		return null;
	}
	return (bool) preg_match( '#^HTTP/\d\.\d\s+2\d\d#', $status );
}

/**
 * Blocking test send (a human is waiting on the /test-telegram route).
 *
 * B134: The primary path uses fatalguard_tg_socket_send() — the same
 * raw fsockopen transport that real fatal alerts use — so a passing test
 * confirms the real crash-path works (hosts with fsockopen in disable_functions
 * will surface a failure here too). wp_remote_post is used ONLY as a fallback
 * diagnostic: if the socket path fails but wp_remote_post succeeds, the message
 * returned to the admin explains the mismatch (fsockopen issue, not Telegram).
 *
 * Takes no chat/message from the request — sends a fixed string to the
 * configured chat.
 *
 * @return array{ok:bool,message:string}
 */
function fatalguard_telegram_test(): array {
	if ( ! fatalguard_telegram_configured() ) {
		return array( 'ok' => false, 'message' => 'Telegram is not configured (set telegram-bot-token and telegram-chat-id).' );
	}
	$cfg  = fatalguard_telegram_config();
	$site = function_exists( 'home_url' ) ? home_url( '/' ) : '';
	$text = "✅ FatalGuard test alert\nSite: {$site}\nIf you can read this, fatal alerts will reach you.";

	// B134: exercise the real send path (fsockopen) first.
	$socket_result = fatalguard_tg_socket_send( $cfg['bot_token'], $cfg['chat_id'], $text );

	if ( true === $socket_result ) {
		fatalguard_tg_breaker_record( true );
		// B068: a successful test-send is proof the channel works — clear stale failure notices.
		fatalguard_clear_alert_failures( 'Telegram', 'all' );
		return array( 'ok' => true, 'message' => 'Sent. Check your Telegram.' );
	}

	// Socket failed or timed out — fall back to wp_remote_post to distinguish
	// an fsockopen-specific problem from a real Telegram rejection.
	$resp = wp_remote_post(
		'https://' . FATALGUARD_DL_TG_HOST . '/bot' . $cfg['bot_token'] . '/sendMessage',
		array(
			'timeout'  => 8,
			'blocking' => true,
			'body'     => array(
				'chat_id' => $cfg['chat_id'],
				'text'    => $text,
			),
		)
	);

	if ( is_wp_error( $resp ) ) {
		$wp_err = $resp->get_error_message();
		if ( null === $socket_result ) {
			return array( 'ok' => false, 'message' => 'Socket send timed out (slow response); wp_remote_post also failed: ' . $wp_err );
		}
		return array( 'ok' => false, 'message' => 'Socket send failed and wp_remote_post also failed: ' . $wp_err . '. Check fsockopen availability on this host.' );
	}
	$code = (int) wp_remote_retrieve_response_code( $resp );
	if ( 200 === $code ) {
		// wp_remote_post succeeded but socket path failed — indicates fsockopen issue.
		if ( false === $socket_result ) {
			return array( 'ok' => false, 'message' => 'Telegram is reachable (wp_remote_post OK) but the real fatal-alert path (fsockopen) failed. Check disable_functions on this host.' );
		}
		// socket_result is null (slow read) + wp_remote_post succeeded → best effort ok.
		fatalguard_tg_breaker_record( true );
		fatalguard_clear_alert_failures( 'Telegram', 'all' );
		return array( 'ok' => true, 'message' => 'Sent (socket timed out on read; wp_remote_post confirmed delivery). Check your Telegram.' );
	}
	$body = (string) wp_remote_retrieve_body( $resp );
	$desc = '';
	$json = json_decode( $body, true );
	if ( is_array( $json ) && ! empty( $json['description'] ) ) {
		$desc = ' — ' . $json['description'];
	}
	return array( 'ok' => false, 'message' => "Telegram returned HTTP {$code}{$desc}" );
}

/* ---- file-based throttle/breaker state (DB-independent) ---- */

function fatalguard_tg_dedupe_ok( string $signature ): bool {
	if ( '' === $signature ) {
		return true;
	}
	$file = fatalguard_dir() . '/.tg-' . substr( hash( 'sha256', $signature ), 0, 16 );
	$now  = time();
	if ( is_file( $file ) && ( $now - (int) @filemtime( $file ) ) < FATALGUARD_DL_TG_DEDUPE_WINDOW ) {
		return false;
	}
	@touch( $file );
	clearstatcache( true, $file );
	return true;
}

function fatalguard_tg_burst_ok(): bool {
	$file = fatalguard_dir() . '/.tg-burst';
	$now  = time();
	$data = array( 'start' => $now, 'count' => 0 );
	if ( is_file( $file ) ) {
		$raw = @file_get_contents( $file );
		$dec = $raw ? json_decode( $raw, true ) : null;
		if ( is_array( $dec ) && isset( $dec['start'], $dec['count'] ) ) {
			$data = $dec;
		}
	}
	if ( ( $now - (int) $data['start'] ) >= FATALGUARD_DL_TG_BURST_WINDOW ) {
		$data = array( 'start' => $now, 'count' => 0 );
	}
	if ( (int) $data['count'] >= FATALGUARD_DL_TG_BURST_MAX ) {
		return false;
	}
	$data['count'] = (int) $data['count'] + 1;
	@file_put_contents( $file, wp_json_encode( $data ), LOCK_EX );
	return true;
}

function fatalguard_tg_breaker_open(): bool {
	$file = fatalguard_dir() . '/.tg-breaker';
	if ( ! is_file( $file ) ) {
		return false;
	}
	$dec = json_decode( (string) @file_get_contents( $file ), true );
	if ( ! is_array( $dec ) ) {
		return false;
	}
	return ! empty( $dec['until'] ) && time() < (int) $dec['until'];
}

function fatalguard_tg_breaker_record( bool $ok ): void {
	$file = fatalguard_dir() . '/.tg-breaker';
	$dec  = is_file( $file ) ? json_decode( (string) @file_get_contents( $file ), true ) : null;
	$fails = ( is_array( $dec ) && isset( $dec['fails'] ) ) ? (int) $dec['fails'] : 0;

	if ( $ok ) {
		if ( is_file( $file ) ) {
			@unlink( $file );
		}
		return;
	}
	$fails++;
	$state = array( 'fails' => $fails );
	if ( $fails >= FATALGUARD_DL_TG_BREAK_FAILS ) {
		$state['until'] = time() + FATALGUARD_DL_TG_BREAK_COOLDN;
		$state['fails'] = 0;
	}
	@file_put_contents( $file, wp_json_encode( $state ), LOCK_EX );
}

/**
 * Record an alert-delivery failure for wp-admin to surface. Writes the SAME
 * option the Pro channels use, so Telegram failures inherit the free admin
 * notice + clear-on-settings-save. Kept in the FREE module because Telegram is a
 * free feature — a free user must still learn their bot is broken.
 */
function fatalguard_record_alert_failure( string $channel, string $host, string $detail, ?int $time = null ): void {
	// B069: callers that replay a STAGED failure (the drop-in importer) pass the
	// original occurrence time so the admin notice reflects when the fatal actually
	// happened, not when WP next booted and imported it. Live callers omit it → now.
	$stamp = ( null === $time ) ? time() : $time;
	fatalguard_mutate_delivery_failures(
		static function ( array $list ) use ( $channel, $host, $detail, $stamp ) {
			array_unshift(
				$list,
				array(
					'channel' => $channel,
					'host'    => $host,
					'detail'  => $detail,
					'time'    => $stamp,
				)
			);
			return array_slice( $list, 0, 20 );
		}
	);
}

/**
 * B145: serialize read-modify-write of the delivery-failures option. record_* and
 * clear_* are get_option → mutate → update_option; two concurrent admin/cron
 * requests can interleave and lose entries. A sidecar advisory lock makes the RMW
 * atomic across processes — we re-read the option INSIDE the lock so each writer
 * sees the previous writer's committed value. Runs only in WP context (never the
 * pre-WP crash path) so a short blocking lock is fine. Caveat: under a persistent
 * object cache the RMW still races at the cache layer and a file lock can't close
 * that; acceptable for this low-rate, best-effort notice list.
 *
 * @param callable $mutator fn(array $list): array — current list in, new list out.
 */
function fatalguard_mutate_delivery_failures( callable $mutator ): void {
	if ( ! function_exists( 'get_option' ) ) {
		return;
	}
	// Guard the lock behind dir() availability: in real runtime logging-state.php is
	// always loaded so we get the flock; in narrow contexts where the log dir helper
	// isn't defined we degrade to an unlocked RMW (the pre-B145 behaviour) rather than
	// fatal on an undefined function.
	$lock = function_exists( 'fatalguard_dir' )
		? @fopen( fatalguard_dir() . '/.fail-lock', 'c' )
		: false;
	if ( $lock ) {
		@flock( $lock, LOCK_EX );
	}
	$list = get_option( 'fatalguard_delivery_failures', array() );
	$list = is_array( $list ) ? $list : array();
	$new  = $mutator( $list );
	if ( is_array( $new ) && $new !== $list ) {
		update_option( 'fatalguard_delivery_failures', $new, false );
	}
	if ( $lock ) {
		@flock( $lock, LOCK_UN );
		@fclose( $lock );
	}
}

/**
 * Fold drop-in-staged Telegram failures into the delivery-failures option. The
 * drop-in runs pre-WP (no options API) so it stages failures in a capped
 * .tg-fail file; import them on the next admin request, then delete it. Reading
 * then unlinking makes this idempotent — a failure is surfaced exactly once.
 */
function fatalguard_import_dropin_tg_fails(): void {
	$file = fatalguard_dir() . '/.tg-fail';
	if ( ! is_file( $file ) ) {
		return;
	}
	// B069/B178 (importer half): the drop-in appends to .tg-fail on the crash path.
	// A plain read-then-unlink here would drop any entry the drop-in appended between
	// our read and our unlink. rename() is atomic on POSIX: we claim the current file
	// under a private name, so a concurrent drop-in append lands in a FRESH .tg-fail
	// (imported on the next admin request) and nothing is lost. If the rename loses a
	// race (another importer already claimed it), bail — the winner surfaces the rows.
	$claim = $file . '.importing';
	if ( ! @rename( $file, $claim ) ) {
		return;
	}
	$raw = (string) @file_get_contents( $claim );
	@unlink( $claim );
	$rows = json_decode( $raw, true );
	if ( ! is_array( $rows ) ) {
		return;
	}
	// Oldest first so the newest failure ends up at the front after array_unshift.
	foreach ( array_reverse( $rows ) as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$code   = isset( $row['code'] ) ? (int) $row['code'] : 0;
		$detail = $code ? ( 'HTTP ' . $code ) : (string) ( $row['detail'] ?? 'send failed' );
		// B069: preserve the staged occurrence time so the notice isn't re-stamped to import time.
		$staged_time = isset( $row['time'] ) ? (int) $row['time'] : null;
		fatalguard_record_alert_failure( 'Telegram', FATALGUARD_DL_TG_HOST, $detail, $staged_time );
	}
}
add_action( 'admin_init', 'fatalguard_import_dropin_tg_fails' );

/**
 * Drop ALL prior recorded failures for one channel — real send-failures AND
 * health-check entries. Called from paths that are DIRECT evidence the channel
 * works: a successful "Send test", a successful real send, or a channel being
 * unconfigured/unlicensed (B068 / B099 / B146 / B159).
 *
 * The weekly/daily HEALTH-CHECK probe must NOT use this — a getMe/getChat probe
 * or a webhook OPTIONS probe cannot prove a past real fatal was delivered, so it
 * calls fatalguard_clear_healthcheck_failures() instead, which clears only
 * the 'weekly health check:' entries and leaves real send-failures flagged
 * (B071 / B072).
 */
function fatalguard_clear_alert_failures( string $channel, string $scope = 'healthcheck' ): void {
	// Default scope keeps the B071/B072 contract: a probe (or any caller that
	// can't prove a real send) clears ONLY 'weekly health check:' entries and
	// leaves real send-failures flagged. Callers with direct proof the channel
	// works (successful "Send test" / real send / channel unconfigured) pass
	// 'all' to also drop real send-failures (B068 / B099 / B146 / B159).
	if ( 'all' !== $scope ) {
		fatalguard_clear_healthcheck_failures( $channel );
		return;
	}
	// B145: mutate under the shared lock so a concurrent record_* doesn't lose the clear.
	fatalguard_mutate_delivery_failures(
		static function ( array $list ) use ( $channel ) {
			return array_values( array_filter(
				$list,
				static function ( $e ) use ( $channel ) {
					return ! is_array( $e ) || ( $e['channel'] ?? '' ) !== $channel;
				}
			) );
		}
	);
}

// ---------------------------------------------------------------------------
// Weekly SILENT health check. A Telegram error is almost always a persistent
// config problem (rotated token, bot removed from the chat) — and the crashed
// page is the worst time to discover it. So once a week, while the site is UP
// and the admin can act, validate the token + chat via the Bot API WITHOUT
// posting a message. A definitive failure records a delivery failure → the
// admin notice; a transient network blip is ignored (retried next week).
// ---------------------------------------------------------------------------
const FATALGUARD_DL_TG_HEALTH_HOOK = 'fatalguard_telegram_healthcheck';

add_filter( 'cron_schedules', 'fatalguard_tg_health_schedule' );
add_action( 'init', 'fatalguard_tg_health_manage' );
add_action( FATALGUARD_DL_TG_HEALTH_HOOK, 'fatalguard_tg_healthcheck' );
add_action( 'admin_init', 'fatalguard_tg_health_staleness_check' );

/** Own daily schedule — B129: weekly leaves a broken channel undetected for up to 6 days. */
function fatalguard_tg_health_schedule( array $schedules ): array {
	if ( ! isset( $schedules['fatalguard_tg_weekly'] ) ) {
		$schedules['fatalguard_tg_weekly'] = array( 'interval' => DAY_IN_SECONDS, 'display' => 'Once daily (FatalGuard Telegram health)' );
	}
	return $schedules;
}

/**
 * Schedule the weekly probe while ANY alert channel wants it. Telegram (free)
 * wants it when configured; Pro channels opt in via the filter (so a Pro-only,
 * no-Telegram site still gets a weekly checkup). The one event drives every
 * channel's callback.
 */
function fatalguard_tg_health_manage(): void {
	// B144: when the kill-switch is active, treat the healthcheck as unwanted and
	// clear any existing schedule so the cron doesn't fire spuriously.
	if ( fatalguard_is_disabled() ) {
		$has = (bool) wp_next_scheduled( FATALGUARD_DL_TG_HEALTH_HOOK );
		if ( $has ) {
			wp_clear_scheduled_hook( FATALGUARD_DL_TG_HEALTH_HOOK );
		}
		return;
	}

	$want = fatalguard_telegram_configured()
		|| (bool) apply_filters( 'fatalguard_want_alerts_healthcheck', false );
	$has  = (bool) wp_next_scheduled( FATALGUARD_DL_TG_HEALTH_HOOK );
	if ( $want && ! $has ) {
		// Deterministic weekly slot so toggling config doesn't drift the run time.
		$next = strtotime( 'next monday 09:00 UTC' );
		if ( false === $next || $next <= time() ) {
			$next = time() + WEEK_IN_SECONDS;
		}
		wp_schedule_event( $next, 'fatalguard_tg_weekly', FATALGUARD_DL_TG_HEALTH_HOOK );
	} elseif ( ! $want && $has ) {
		wp_clear_scheduled_hook( FATALGUARD_DL_TG_HEALTH_HOOK );
	}
}

/**
 * B204: On admin page loads, detect a stale healthcheck (WP-Cron disabled or
 * zero-traffic site). If the last-run timestamp is older than DAY_IN_SECONDS × 9
 * (a 1-week cadence plus a 2-day grace period), surface a delivery-failure notice
 * so the admin is aware the automated probe isn't running.
 *
 * Throttled via the same last-run option — the comparison only fires when there
 * IS a last-run record (so first-run sites don't get a spurious notice) and the
 * staleness threshold is intentionally generous to avoid false positives.
 */
function fatalguard_tg_health_staleness_check(): void {
	if ( ! function_exists( 'get_option' ) ) {
		return;
	}
	if ( fatalguard_is_disabled() ) {
		return;
	}
	$last_run = (int) get_option( 'fatalguard_dl_health_last_run', 0 );
	if ( 0 === $last_run ) {
		return; // never ran — newly installed, no false positive
	}
	$grace    = (int) ( DAY_IN_SECONDS * 9 ); // 1 week + 2 day grace
	$overdue  = ( time() - $last_run ) > $grace;
	if ( ! $overdue ) {
		return;
	}
	// Only surface one notice; don't pile them on every admin load.
	$existing = get_option( 'fatalguard_delivery_failures', array() );
	$existing = is_array( $existing ) ? $existing : array();
	foreach ( $existing as $e ) {
		if ( is_array( $e ) && 0 === strpos( (string) ( $e['detail'] ?? '' ), 'health check overdue' ) ) {
			return;
		}
	}
	fatalguard_record_alert_failure(
		'Telegram',
		FATALGUARD_DL_TG_HOST,
		'health check overdue — last ran ' . round( ( time() - $last_run ) / DAY_IN_SECONDS ) . ' day(s) ago; WP-Cron may be disabled or traffic is too low to trigger it'
	);
}

/**
 * Clear only delivery-failure entries whose detail starts with 'weekly health check:'
 * for a given channel. Real send-failures (HTTP 403, 'send failed', etc.) are left
 * intact — a GET probe (getMe/getChat or alive-check) cannot prove sendMessage works.
 * B071 / B072.
 */
function fatalguard_clear_healthcheck_failures( string $channel ): void {
	// B145: mutate under the shared lock so a concurrent record_* doesn't lose the clear.
	fatalguard_mutate_delivery_failures(
		static function ( array $list ) use ( $channel ) {
			return array_values( array_filter(
				$list,
				static function ( $e ) use ( $channel ) {
					if ( ! is_array( $e ) || ( $e['channel'] ?? '' ) !== $channel ) {
						return true; // keep entries for other channels
					}
					// For this channel: keep ONLY real send-failures (detail does NOT start with 'weekly health check:').
					return 0 !== strpos( (string) ( $e['detail'] ?? '' ), 'weekly health check:' );
				}
			) );
		}
	);
}

/**
 * The weekly probe: validate, then record OR clear the Telegram health entry.
 *
 * B204: records a 'fatalguard_dl_health_last_run' timestamp on every invocation so
 * the admin page can detect that WP-Cron has stopped firing (DISABLE_WP_CRON
 * without a system cron, zero-traffic site).
 *
 * B142: persistent network failures are now escalated. A single 'network'
 * result is still treated as a transient blip; after
 * FATALGUARD_DL_TG_HEALTH_NET_FAIL_THRESHOLD consecutive network-fail probes the
 * function records an alert failure so the admin notice fires.
 *
 * B138/B140/B177/B180: opportunistic GC — glob the log dir's .tg-* stamp files
 * and unlink any entry older than the dedupe window, preserving sentinel files
 * (.tg-breaker, .tg-burst, .tg-fail) which have their own semantics.
 */
function fatalguard_tg_healthcheck(): void {
	// B070: do nothing when the kill-switch is active — no probe, no failure record.
	if ( fatalguard_is_disabled() ) {
		return;
	}

	// B204: stamp the run time so the admin page can detect a stale/broken cron.
	if ( function_exists( 'update_option' ) ) {
		update_option( 'fatalguard_dl_health_last_run', time(), false );
	}

	$cfg = fatalguard_telegram_configured() ? fatalguard_telegram_config() : null;
	if ( $cfg ) {
		$res = fatalguard_tg_probe( $cfg['bot_token'], $cfg['chat_id'] );
		if ( 'ok' === $res['status'] ) {
			// B072: probe-ok only clears healthcheck-tagged entries; real send failures
			// (HTTP 403, blocked bot, etc.) must persist until a real send succeeds.
			fatalguard_clear_healthcheck_failures( 'Telegram' ); // recovered → silence the weekly health check notice
			// Reset any consecutive network-fail counter.
			if ( function_exists( 'delete_option' ) ) {
				delete_option( 'fatalguard_dl_tg_network_fails' );
			}
		} elseif ( 'config' === $res['status'] ) {
			fatalguard_record_alert_failure( 'Telegram', FATALGUARD_DL_TG_HOST, 'weekly health check: ' . $res['detail'] );
			if ( function_exists( 'delete_option' ) ) {
				delete_option( 'fatalguard_dl_tg_network_fails' );
			}
		} else {
			// B142: 'network' status — increment consecutive fail_count; escalate at threshold.
			$network_fail_count = 0;
			if ( function_exists( 'get_option' ) ) {
				$network_fail_count = (int) get_option( 'fatalguard_dl_tg_network_fails', 0 );
			}
			$network_fail_count++;
			if ( function_exists( 'update_option' ) ) {
				update_option( 'fatalguard_dl_tg_network_fails', $network_fail_count, false );
			}
			if ( $network_fail_count >= FATALGUARD_DL_TG_HEALTH_NET_FAIL_THRESHOLD ) {
				fatalguard_record_alert_failure(
					'Telegram',
					FATALGUARD_DL_TG_HOST,
					'weekly health check: cannot reach api.telegram.org from this server after ' . $network_fail_count . ' consecutive attempt(s) — check outbound HTTPS on this host'
				);
			}
		}
	}

	// Pro: probe each distinct extra Telegram target too, so a revoked per-target
	// bot/chat is surfaced instead of silently dropping routed alerts.
	if ( function_exists( 'fatalguard_pro_targets' ) ) {
		foreach ( fatalguard_pro_targets() as $t ) {
			$chat  = isset( $t['chat_id'] ) ? (string) $t['chat_id'] : '';
			$token = ! empty( $t['bot_token'] ) ? (string) $t['bot_token'] : (string) ( $cfg['bot_token'] ?? '' );
			if ( '' === $chat || '' === $token ) {
				continue;
			}
			$label = 'Telegram target ' . $chat;
			$tres  = fatalguard_tg_probe( $token, $chat );
			if ( 'ok' === $tres['status'] ) {
				// B072: selective clear — only healthcheck-tagged entries.
				fatalguard_clear_healthcheck_failures( $label );
			} elseif ( 'config' === $tres['status'] ) {
				fatalguard_record_alert_failure( $label, FATALGUARD_DL_TG_HOST, 'weekly health check: ' . $tres['detail'] );
			}
		}
	}

	// B138/B140/B177/B180: opportunistic GC — remove dedupe stamp files that are
	// older than the dedupe window so the log dir doesn't accumulate unboundedly.
	// Sentinel files (.tg-breaker, .tg-burst, .tg-fail) are always preserved.
	$gc_dir        = fatalguard_dir();
	$gc_sentinels  = array( '.tg-breaker', '.tg-burst', '.tg-fail' );
	$gc_stamps     = glob( $gc_dir . '/.tg-*' );
	if ( is_array( $gc_stamps ) ) {
		$gc_cutoff = time() - FATALGUARD_DL_TG_DEDUPE_WINDOW;
		foreach ( $gc_stamps as $gc_file ) {
			if ( in_array( basename( $gc_file ), $gc_sentinels, true ) ) {
				continue; // preserve burst/breaker/fail sentinels
			}
			if ( @filemtime( $gc_file ) < $gc_cutoff ) {
				@unlink( $gc_file );
			}
		}
	}
}

/**
 * Validate the bot token (getMe) + chat reachability (getChat) WITHOUT sending.
 *
 * @return array{status:string,detail:string} status: ok | config | network.
 */
function fatalguard_tg_probe( string $token, string $chat_id ): array {
	$base = 'https://' . FATALGUARD_DL_TG_HOST . '/bot' . $token;

	$me = fatalguard_tg_api_get( $base . '/getMe' );
	if ( 'network' === $me['class'] ) {
		return array( 'status' => 'network', 'detail' => $me['detail'] );
	}
	if ( ! $me['ok'] ) {
		return array( 'status' => 'config', 'detail' => 'token invalid (' . $me['detail'] . ')' );
	}

	$chat = fatalguard_tg_api_get( $base . '/getChat?chat_id=' . rawurlencode( $chat_id ) );
	if ( 'network' === $chat['class'] ) {
		return array( 'status' => 'network', 'detail' => $chat['detail'] );
	}
	if ( ! $chat['ok'] ) {
		return array( 'status' => 'config', 'detail' => 'chat unreachable (' . $chat['detail'] . ')' );
	}

	return array( 'status' => 'ok', 'detail' => '' );
}

/**
 * GET a Bot API method (blocking; cron context), retrying once on a NETWORK
 * error only — a 4xx is a definitive config verdict. Surfaces Telegram's JSON
 * `description` so the notice can say why.
 *
 * @return array{ok:bool,class:string,detail:string} class: http | network.
 */
function fatalguard_tg_api_get( string $url ): array {
	$last = 'no response';
	for ( $try = 0; $try < 2; $try++ ) {
		$r = wp_remote_get( $url, array( 'timeout' => 8 ) );
		if ( is_wp_error( $r ) ) {
			$last = $r->get_error_message();
			continue; // network — retry once
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		if ( $code >= 200 && $code < 300 ) {
			return array( 'ok' => true, 'class' => 'http', 'detail' => 'HTTP ' . $code );
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $r ), true );
		$desc = ( is_array( $body ) && ! empty( $body['description'] ) ) ? (string) $body['description'] : '';
		return array( 'ok' => false, 'class' => 'http', 'detail' => 'HTTP ' . $code . ( '' !== $desc ? ' — ' . $desc : '' ) );
	}
	return array( 'ok' => false, 'class' => 'network', 'detail' => $last );
}
