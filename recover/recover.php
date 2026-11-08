<?php
/**
 * FatalGuard — one-tap recovery engine.
 *
 * Loaded very early through a mu-plugin loader (mu-plugins run BEFORE regular
 * plugins), so a plugin that fatals the whole site on load can still be switched
 * off from here. Reached by a signed link in the alert:
 *   /?fatalguard_recover=<slug>.<issued>.<sig>
 *
 * Auth is the signed token itself (the admin can't log in — wp-admin is down too),
 * exactly like WordPress core Recovery Mode's email link. Scope is intentionally
 * tiny: deactivate the one named plugin. Reversible; nothing is deleted.
 *
 * Defensive by contract: any problem → bail and let the request continue. It only
 * ever does anything when the recover parameter is present, so normal traffic is
 * untouched.
 *
 * @package FatalGuard
 */

defined( 'ABSPATH' ) || exit;

// Re-include guard (define() is runtime, so unlike a function_exists() check it is
// NOT defeated by PHP hoisting the functions below to compile time).
if ( defined( 'FATALGUARD_RECOVER_ENGINE' ) ) {
	return;
}
define( 'FATALGUARD_RECOVER_ENGINE', 1 );
define( 'FATALGUARD_RECOVER_TTL', 14400 ); // signed links are valid 4 hours (phone-reaction window)

/**
 * Signing key derived from the site's auth salt.
 *
 * SECURITY: never sign or verify with a public/predictable key. At mu-plugin load
 * time (before pluggable.php defines wp_salt()) an auto-salt install has no AUTH_SALT
 * constant, so $salt would fall through to '' and the key would become the public
 * constant sha256('fatalguard-recover|…'). The default WordPress placeholder
 * ('put your unique phrase here') is equally public. In either case we return '' as a
 * sentinel so callers refuse to operate rather than trusting a forgeable key.
 */
function fatalguard_recover_key(): string {
	$salt = '';
	if ( defined( 'AUTH_SALT' ) && '' !== AUTH_SALT ) {
		$salt = (string) AUTH_SALT;
	} elseif ( defined( 'SECURE_AUTH_SALT' ) && '' !== SECURE_AUTH_SALT ) {
		$salt = (string) SECURE_AUTH_SALT;
	} elseif ( function_exists( 'wp_salt' ) ) {
		$salt = (string) wp_salt( 'auth' );
	}
	// No real secret available → refuse to derive a key (returns '' sentinel).
	if ( $salt === '' || $salt === 'put your unique phrase here' ) {
		return '';
	}
	return hash( 'sha256', 'fatalguard-recover|' . $salt );
}

/**
 * HMAC for a (slug, issued) pair — truncated, constant-time compared on verify.
 * Returns '' when no signing key is available so tokens can never validate.
 */
function fatalguard_recover_sign( string $slug, int $issued ): string {
	$key = fatalguard_recover_key();
	if ( '' === $key ) {
		return '';
	}
	return substr( hash_hmac( 'sha256', $slug . '|' . $issued, $key ), 0, 40 );
}

/** Build a fresh signed recovery link for a culprit plugin slug. */
function fatalguard_recover_link( string $slug ): string {
	$issued = time();
	$token  = $slug . '.' . $issued . '.' . fatalguard_recover_sign( $slug, $issued );
	$base   = function_exists( 'home_url' ) ? home_url( '/' ) : '/';
	return add_query_arg( 'fatalguard_recover', $token, $base );
}

/**
 * Parse + validate a token. Returns [slug, issued, ok, reason]; reason is one of
 * 'malformed' | 'bad-sig' | 'expired' | '' (ok).
 */
function fatalguard_recover_parse( string $token ): array {
	$parts = explode( '.', $token );
	if ( 3 !== count( $parts ) ) {
		return array( '', 0, false, 'malformed' );
	}
	$slug   = preg_replace( '/[^a-z0-9_\-]/i', '', (string) $parts[0] );
	$issued = (int) $parts[1];
	$sig    = (string) $parts[2];
	if ( '' === $slug || $issued <= 0 ) {
		return array( '', 0, false, 'malformed' );
	}
	// No signing key available (public/predictable salt) → refuse all tokens.
	$expected = fatalguard_recover_sign( $slug, $issued );
	if ( '' === $expected || ! hash_equals( $expected, $sig ) ) {
		return array( $slug, $issued, false, 'bad-sig' );
	}
	// Expiry is two-sided: reject stale tokens AND future-dated ones (beyond a small
	// clock-skew allowance) so a single forged far-future token can't be a permanent backdoor.
	if ( time() - $issued > FATALGUARD_RECOVER_TTL || $issued > time() + 300 ) {
		return array( $slug, $issued, false, 'expired' );
	}
	return array( $slug, $issued, true, '' );
}

/**
 * B190: load the 'fatalguard' text domain for the recovery screens, which render
 * pre-'init' (mu-plugin load) — before WordPress would normally load it. The free
 * wordpress.org build ships no bundled .mo (translate.wordpress.org delivers them to
 * WP_LANG_DIR/plugins/), so try that canonical location first; the Freemius Pro build's
 * bundled languages/ dir is the fallback. No-op when l10n isn't available yet or the
 * catalogue is already loaded/absent.
 */
function fatalguard_recover_load_textdomain(): void {
	if ( ! function_exists( 'load_textdomain' ) || ! function_exists( 'get_locale' ) ) {
		return;
	}
	if ( function_exists( 'is_textdomain_loaded' ) && is_textdomain_loaded( 'fatalguard' ) ) {
		return;
	}
	$locale     = get_locale();
	$candidates = array();
	if ( defined( 'WP_LANG_DIR' ) ) {
		$candidates[] = WP_LANG_DIR . '/plugins/fatalguard-' . $locale . '.mo';
	}
	$candidates[] = dirname( __DIR__ ) . '/languages/fatalguard-' . $locale . '.mo';
	foreach ( $candidates as $mofile ) {
		if ( is_readable( $mofile ) ) {
			load_textdomain( 'fatalguard', $mofile );
			return;
		}
	}
}

/** Entry point — called at mu-plugin load. Renders + exits, or returns to let WP continue. */
function fatalguard_recover_run(): void {
	// This endpoint authenticates via an HMAC-signed one-tap token in the URL, NOT a
	// WP nonce: the recovery link must work for a logged-out admin when wp-admin is
	// down. fatalguard_recover_parse() verifies the signature, single-use marker and
	// TTL, so nonce verification is neither available nor applicable here.
	// phpcs:disable WordPress.Security.NonceVerification
	if ( empty( $_GET['fatalguard_recover'] ) ) {
		return;
	}
	try {
		// B190: load the plugin text domain NOW. Recovery screens render at mu-plugin
		// load, before the normal 'init' textdomain load, so without this __() would emit
		// English even on a localized site during the emergency flow.
		fatalguard_recover_load_textdomain();

		$token                          = sanitize_text_field( wp_unslash( $_GET['fatalguard_recover'] ) );
		list( $slug, $issued, $ok, $why ) = fatalguard_recover_parse( $token );

		if ( ! $ok && 'expired' === $why ) {
			// B085: secure in-screen renewal. Reaching 'expired' means the SIGNATURE was
			// valid (parse returns 'bad-sig' before 'expired'), so the requester holds a
			// genuinely-signed link for THIS slug — not a mint-any-slug forgery. On the
			// renew POST, re-verify the posted token and, only if it is validly-signed-
			// but-expired for the same slug, mint a fresh link and 302 to it (a clean GET
			// then renders the live landing whose form posts to the fresh token).
			// Renewal has an ABSOLUTE age cap: without one, a leaked expired token could
			// be re-renewed forever (each renewal mints a fresh signature, so the
			// single-use marker never blocks the chain). Past the cap the expired screen
			// renders without the renew form — manual wp-admin is the only path left.
			$renew_cap = 7 * 86400; // seconds; links renewable for at most 7 days after issue
			$is_post   = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
			if ( $is_post && ! empty( $_POST['fatalguard_renew'] ) ) {
				$posted = isset( $_POST['fatalguard_token'] ) ? sanitize_text_field( wp_unslash( $_POST['fatalguard_token'] ) ) : $token;
				list( $r_slug, $r_issued, , $r_why ) = fatalguard_recover_parse( $posted );
				if ( 'expired' === $r_why && $r_slug === $slug && ( time() - (int) $r_issued ) <= $renew_cap ) {
					$fresh = fatalguard_recover_link( $slug );
					if ( ! headers_sent() ) {
						header( 'Location: ' . $fresh, true, 302 );
					}
					exit;
				}
			}
			$renewable = ( time() - (int) $issued ) <= $renew_cap;
			fatalguard_recover_screen_expired( $slug, $renewable ? $token : '' );
		}
		if ( ! $ok ) {
			return; // malformed / forged → act as if the param wasn't there
		}

		$sig    = fatalguard_recover_sign( $slug, $issued );
		$isPost = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
		$confirm = $isPost && ! empty( $_POST['fatalguard_confirm'] );

		if ( $confirm ) {
			// B086: on replay, read the stored outcome instead of hardcoding true.
			if ( fatalguard_recover_is_used( $sig ) ) {
				$stored = get_option( fatalguard_recover_used_key( $sig ), array() );
				$replay_done   = is_array( $stored ) && isset( $stored['done'] )   ? (bool) $stored['done']   : false;
				$replay_health = is_array( $stored ) && array_key_exists( 'health', $stored ) ? $stored['health'] : null;
				fatalguard_recover_screen_done( $slug, fatalguard_recover_pretty( $slug ), $replay_health, $replay_done );
			}

			// B179: atomic claim — add_option() is a test-and-set: it returns false (and
			// does nothing) if the row already exists, unlike update_option() which always
			// overwrites. Claiming the used-key HERE, BEFORE deactivation, ensures only
			// one concurrent POST request proceeds even when two arrive simultaneously.
			// A false return means another request already claimed the slot; treat as replay.
			$used_key     = fatalguard_recover_used_key( $sig );
			$claimed      = function_exists( 'add_option' )
				? add_option( $used_key, array( 'done' => false, 'health' => null ), '', 'no' )
				: true; // fallback: assume claimed (safe — worst case we skip one deactivation)
			if ( false === $claimed ) {
				// Another request already claimed this token — serve the stored outcome.
				$stored        = get_option( $used_key, array() );
				$replay_done   = is_array( $stored ) && isset( $stored['done'] )   ? (bool) $stored['done']   : false;
				$replay_health = is_array( $stored ) && array_key_exists( 'health', $stored ) ? $stored['health'] : null;
				fatalguard_recover_screen_done( $slug, fatalguard_recover_pretty( $slug ), $replay_health, $replay_done );
			}

			$done   = fatalguard_recover_deactivate( $slug );
			$health = fatalguard_recover_healthcheck();
			fatalguard_recover_mark_used( $sig, $done, $health );
			fatalguard_recover_screen_done( $slug, fatalguard_recover_pretty( $slug ), $health, $done );
		}

		// B089: if the culprit is no longer active (site already recovered via another
		// link), show a calm "already handled" screen instead of the full INCIDENT landing.
		$active_plugins = function_exists( 'get_option' ) ? (array) get_option( 'active_plugins', array() ) : array();
		$slug_inactive  = ! fatalguard_recover_slug_in_active( $slug, $active_plugins );
		if ( $slug_inactive ) {
			fatalguard_recover_screen_done( $slug, fatalguard_recover_pretty( $slug ), null, false );
		}

		fatalguard_recover_screen_landing( $slug, fatalguard_recover_pretty( $slug ), $token, $issued );
	} catch ( \Throwable $e ) {
		return; // never brick a request over the recovery flow
	}
	// phpcs:enable WordPress.Security.NonceVerification
}

/* ---- actions ---- */

/**
 * B089: Read-only membership check — is $slug currently in $active_plugins?
 * Uses the same three matching predicates as fatalguard_recover_deactivate()
 * so the GET guard and the deactivation mutator stay in sync.
 *
 * @param string   $slug   The plugin slug from the recovery token.
 * @param string[] $active The active_plugins array.
 */
function fatalguard_recover_slug_in_active( string $slug, array $active ): bool {
	$norm = static function ( string $s ): string {
		return (string) preg_replace( '/[^a-z0-9_\-]/i', '', $s );
	};
	$want = $norm( $slug );
	foreach ( $active as $p ) {
		$p       = (string) $p;
		$segment = explode( '/', $p )[0];
		if ( $segment === $slug )           { return true; }
		if ( $p === $slug . '.php' )        { return true; }
		if ( '' !== $want && $norm( $segment ) === $want ) { return true; }
	}
	return false;
}

/**
 * Remove the plugin whose folder (or single file) matches $slug from
 * active_plugins. active_plugins entries are 'folder/main.php' (folder plugins)
 * or 'single.php' (single-file plugins).
 *
 * The signed slug may arrive in two forms:
 *  - Folder plugin: 'akismet' (first-segment of 'akismet/akismet.php').
 *  - Single-file (WP-context path): 'hello' (classify_source strips .php first).
 *  - Single-file (drop-in path): 'hellophp' (drop-in strips all non-slug chars).
 *
 * Candidates are gathered across all three predicates (no early return on first
 * hit) so B034 (WP-context .php mismatch) and B149 (normalization collision) are
 * both handled: ≥2 distinct candidates → refuse rather than deactivate the wrong one.
 */
function fatalguard_recover_deactivate( string $slug ): bool {
	if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
		return false;
	}
	$active = (array) get_option( 'active_plugins', array() );
	$norm   = static function ( string $s ): string {
		return (string) preg_replace( '/[^a-z0-9_\-]/i', '', $s );
	};
	$want = $norm( $slug );

	// Collect all candidate active_plugins entries (deduped by entry string).
	$candidates = array();
	foreach ( $active as $p ) {
		$p       = (string) $p;
		$segment = explode( '/', $p )[0];
		// Predicate 1: exact first-segment match (folder plugins, e.g. 'akismet').
		if ( $segment === $slug ) {
			$candidates[ $p ] = true;
			continue;
		}
		// Predicate 2: single-file plugin — WP-context path strips .php, so slug
		// 'hello' should match active_plugins entry 'hello.php' (B034).
		if ( $p === $slug . '.php' ) {
			$candidates[ $p ] = true;
			continue;
		}
		// Predicate 3: dot-stripped normalization (drop-in 'hellophp' path, and
		// folder names that contain dots). Only used when $want is non-empty.
		if ( '' !== $want && $norm( $segment ) === $want ) {
			$candidates[ $p ] = true;
		}
	}

	$matched = array_keys( $candidates );

	if ( count( $matched ) === 0 ) {
		return false; // already off / not found
	}
	if ( count( $matched ) > 1 ) {
		// Ambiguous: multiple active entries share the same normalized slug.
		// Refuse — deactivating the wrong one (B149) is worse than failing safely.
		return false;
	}

	$entry = $matched[0];
	// B205: re-read active_plugins immediately before the write and subtract only our
	// one matched entry from the FRESH array. A concurrent wp-admin activation that
	// landed between the read above and here is then preserved — we never write back a
	// stale whole-array snapshot, only remove a single known entry. This mirrors WP
	// core's own deactivate_plugins() get→diff→update semantics without pulling the
	// admin plugin API into the early recovery flow, shrinking the lost-update window
	// to this single removal. (B179's atomic add_option claim already prevents two
	// concurrent recovery POSTs from both reaching here.)
	$fresh  = (array) get_option( 'active_plugins', array() );
	$active = array_values( array_diff( $fresh, array( $entry ) ) );
	update_option( 'active_plugins', $active );

	// B114: When FatalGuard itself is the culprit, run the full deactivation cleanup
	// inline (drop-in removal, mu-loader removal, cron clearance) so nothing keeps
	// executing while the plugin is nominally off.
	$norm_slug = preg_replace( '/[^a-z0-9_\-]/i', '', $slug );
	if ( 'fatalguard' === $norm_slug ) {
		if ( function_exists( 'fatalguard_on_deactivate' ) ) {
			fatalguard_on_deactivate();
		} else {
			// Inline fallback: clear crons directly so they are never left running.
			if ( function_exists( 'fatalguard_remove_dropin' ) ) {
				fatalguard_remove_dropin();
			}
			if ( function_exists( 'fatalguard_recover_uninstall_loader' ) ) {
				fatalguard_recover_uninstall_loader();
			}
			if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
				wp_clear_scheduled_hook( defined( 'FATALGUARD_DL_DIGEST_HOOK' ) ? FATALGUARD_DL_DIGEST_HOOK : 'fatalguard_weekly_digest' );
				wp_clear_scheduled_hook( 'fatalguard_pro_history_prune' );
				wp_clear_scheduled_hook( 'fatalguard_telegram_healthcheck' );
			}
		}
	}

	return true;
}

/**
 * Best-effort loopback: does the site respond 2xx now? null if we can't tell.
 *
 * B150: accepts an optional $url so the caller can probe the actual crash URL
 * instead of always the homepage. Falls back to the homepage when empty.
 * NOTE: the crash URL is not yet threaded through the token, so we use the
 * homepage as the fallback and label the row honestly in the done screen.
 */
function fatalguard_recover_healthcheck( $url = '' ): ?bool {
	if ( ! function_exists( 'wp_remote_get' ) || ! function_exists( 'home_url' ) ) {
		return null;
	}
	$probe = ( '' !== $url ) ? $url : home_url( '/?fatalguard_ping=1' );
	$resp  = wp_remote_get( $probe, array( 'timeout' => 5, 'redirection' => 1 ) );
	if ( is_wp_error( $resp ) ) {
		return null;
	}
	$code = (int) wp_remote_retrieve_response_code( $resp );
	return $code >= 200 && $code < 400;
}

/* ---- single-use marker ---- */
//
// SECURITY: The recovery token travels in the URL query string (?fatalguard_recover=…),
// so it is written verbatim to web-server access logs, WAF logs, and any log aggregator
// that captures GET query strings. The confirm step uses a POST form so the deactivation
// action itself does not add a second log entry with the token, but the initial GET link
// click will appear in the access log. Site operators with high-security requirements
// should configure access-log scrubbing or rotate logs after a recovery event.
//
// DURABILITY: The used-marker is backed by the WordPress options table (add_option /
// get_option) rather than a transient. Transients backed by a persistent object-cache
// (Redis/Memcached) live only in the cache layer; wp_cache_flush() or LRU eviction erases
// them within the token TTL, silently re-enabling replay. An autoload=no option row in
// wp_options is written directly to the DB and survives any cache flush.

function fatalguard_recover_used_key( string $sig ): string {
	return 'fatalguard_recover_used_' . substr( $sig, 0, 20 );
}
function fatalguard_recover_is_used( string $sig ): bool {
	if ( ! function_exists( 'get_option' ) ) {
		return false;
	}
	// Primary check: the DB-backed option row (survives any object-cache flush).
	return false !== get_option( fatalguard_recover_used_key( $sig ), false );
}
/**
 * Mark a token as used, storing the deactivation outcome so replays can render
 * the correct screen instead of hardcoding $deactivated=true.
 *
 * B086: stores ['done'=>bool, 'health'=>?bool] rather than a bare 1 so the
 * replay branch in fatalguard_recover_run() can pass the real outcome to
 * screen_done() and show the correct screen on back-button re-submits.
 *
 * @param string $sig    The token signature.
 * @param bool   $done   Whether deactivation succeeded (default false).
 * @param ?bool  $health Healthcheck result at time of deactivation (nullable).
 */
function fatalguard_recover_mark_used( string $sig, bool $done = false, ?bool $health = null ): void {
	if ( ! function_exists( 'update_option' ) ) {
		return;
	}
	$key     = fatalguard_recover_used_key( $sig );
	$payload = array( 'done' => $done, 'health' => $health );
	// Primary: persist directly to the DB options table (autoload=no). This row
	// survives wp_cache_flush(), Redis/Memcached eviction, and object-cache drop-in
	// replacement — guaranteeing that the used-marker outlives any cache event within
	// the token TTL (FATALGUARD_RECOVER_TTL, 4 hours).
	update_option( $key, $payload, 'no' );
	// Secondary: warm the object cache with a transient so subsequent is_used() calls
	// skip the DB round-trip on cache-enabled installs. The return value is checked;
	// a false result (cache layer unavailable) is non-fatal because the option row is
	// already the authoritative store.
	if ( function_exists( 'set_transient' ) ) {
		$cached = set_transient( $key, $payload, DAY_IN_SECONDS );
		if ( false === $cached ) {
			// Cache write failed — acceptable; the DB option is the durable guard.
			// Log nothing here: the used-marker is already safe in wp_options.
		}
	}
}

/** A readable plugin name from its slug (header lookup needs admin code we skip; prettify). */
function fatalguard_recover_pretty( string $slug ): string {
	// B152: the drop-in strips dots when minting the token (e.g. 'hello.php' →
	// 'hellophp'). Strip a trailing 'php' (only when it was appended by that
	// dot-stripping, i.e. the slug ends in 'php' but not in '-php' or '_php'
	// which are legitimate slug suffixes) so the display name reads 'Hello' not 'Hellophp'.
	$clean = preg_replace( '/(?<![_\-])php$/i', '', $slug );
	// Normalise dashes and underscores to spaces for readability.
	$name  = str_replace( array( '-', '_' ), ' ', (string) $clean );
	$name  = trim( $name );
	return function_exists( 'ucwords' ) ? ucwords( $name ) : $name;
}

/* ---- alert-text enrichment (one-tap link in Slack/Discord/email/webhook) ---- */

/** Culprit plugin slug from a record's origin/file, or '' if the culprit isn't a plugin. */
function fatalguard_recover_culprit_slug( array $rec ): string {
	$origin = isset( $rec['origin'] ) ? (string) $rec['origin'] : '';
	$file   = isset( $rec['file'] ) ? (string) $rec['file'] : '';

	// B084: mu-plugins are not in active_plugins and cannot be deactivated.
	// Guard before the 'plugin:' branch because classify_source collapses
	// mu-plugins and plugins into the same 'plugin:' prefix.
	if ( '' !== $file && strpos( $file, '/mu-plugins/' ) !== false ) {
		return ''; // mu-plugin — no one-tap deactivate
	}

	if ( 0 === strpos( $origin, 'plugin:' ) ) {
		return (string) preg_replace( '/[^a-z0-9_\-]/i', '', substr( $origin, 7 ) );
	}
	if ( preg_match( '#(?:^|/)wp-content/plugins/([^/]+)/#', $file, $m ) ) {
		return (string) preg_replace( '/[^a-z0-9_\-]/i', '', $m[1] );
	}
	return ''; // theme / core — no one-tap deactivate
}

/**
 * Security plugin denylist — slugs in this set are never auto-deactivated via a
 * one-tap link. An attacker-induced fatal inside a security/firewall plugin
 * (e.g. Wordfence, Sucuri, iThemes Security) would otherwise cause FatalGuard to
 * deliver a legitimate-looking "Deactivate <security-plugin>" link to the admin
 * channel. Plugins on the denylist are excluded from the one-tap flow entirely.
 *
 * @return string[] Normalised slug prefixes that must never receive auto-deactivate links.
 */
function fatalguard_recover_security_denylist(): array {
	return array(
		'wordfence',
		'sucuri-scanner',
		'better-wp-security',
		'all-in-one-wp-security-and-firewall',
		'shield-security',
		'defender-security',
		'security-ninja',
		'bulletproof-security',
		'wp-cerber',
		'anti-malware',
		'malcare-security',
		'jetpack',       // includes WAF/brute-force
	);
}

/**
 * Append the signed one-tap recovery link to an alert's text. Only plugins can be
 * deactivated from the link; theme/core/mu culprits instead get a plain explanation
 * plus a pointer to WordPress Recovery Mode (docs promise both). Hooks the
 * shared alert-text filter (gutenx_debug_logs_alert_text → fatalguard_alert_text),
 * so every channel that uses the shared formatter — Slack, Discord, email, webhook,
 * Telegram targets — carries the link, matching the drop-in's primary Telegram.
 *
 * SECURITY (B148): The culprit slug is derived from the fatal's file path, which an
 * attacker can influence by triggering a crash in a target plugin's public endpoint.
 * Two guards narrow the attack surface:
 *   1. Active-plugins check — only issue a link when the slug is actually in active_plugins.
 *   2. Security denylist — never issue a one-tap link for security/firewall plugins.
 */
function fatalguard_recover_append_link( string $text, array $rec ): string {
	$slug = fatalguard_recover_culprit_slug( is_array( $rec ) ? $rec : array() );
	if ( '' === $slug ) {
		$origin = isset( $rec['origin'] ) ? (string) $rec['origin'] : '';
		$file   = isset( $rec['file'] ) ? (string) $rec['file'] : '';
		if ( 0 === strpos( $origin, 'theme:' ) || 'core' === $origin
			|| ( '' !== $file && ! preg_match( '#(?:^|/)wp-content/plugins/#', $file ) ) ) {
			return $text . "\n\nNo one-tap fix here — a theme, core or must-use file crashed, and deactivating a plugin can't fix that. Open wp-admin, or use WordPress Recovery Mode (the link is in your site-admin email).";
		}
		return $text;
	}

	// B148 guard 1: only issue a deactivation link when the slug is actually in active_plugins.
	$active_plugins = function_exists( 'get_option' ) ? (array) get_option( 'active_plugins', array() ) : array();
	if ( ! fatalguard_recover_slug_in_active( $slug, $active_plugins ) ) {
		return $text;
	}

	// B148 guard 2: never issue a one-tap deactivate link for security/firewall plugins
	// (denylist). An attacker-induced fatal in such a plugin would otherwise deliver a
	// weaponised deactivate link to the admin channel.
	$norm_slug = (string) preg_replace( '/[^a-z0-9_\-]/i', '', strtolower( $slug ) );
	foreach ( fatalguard_recover_security_denylist() as $denied ) {
		if ( $norm_slug === (string) preg_replace( '/[^a-z0-9_\-]/i', '', strtolower( $denied ) ) ) {
			return $text;
		}
	}

	$plugin_name = fatalguard_recover_pretty( $slug );
	return $text
		. "\n\nSecure FatalGuard recovery link — deactivates only \"" . $plugin_name . "\", expires in 4 hours. Verify the domain matches your site before tapping.\n"
		. fatalguard_recover_link( $slug );
}
add_filter( 'fatalguard_alert_text', 'fatalguard_recover_append_link', 10, 2 );

/**
 * The signed one-tap recovery URL for a fatal record, or '' when none applies
 * (theme/core/mu culprit, an inactive plugin, or a denylisted security plugin).
 * Same slug-derivation + B148 guards as fatalguard_recover_append_link(), but
 * returns the URL alone so STRUCTURED channels (the generic JSON webhook) can
 * carry the link as a `recovery_url` field — mirroring what the text channels
 * (Slack/Discord/email/Telegram) get baked into their message body.
 */
function fatalguard_recover_url_for_record( array $rec ): string {
	$slug = fatalguard_recover_culprit_slug( $rec );
	if ( '' === $slug ) {
		return '';
	}
	$active_plugins = function_exists( 'get_option' ) ? (array) get_option( 'active_plugins', array() ) : array();
	if ( ! fatalguard_recover_slug_in_active( $slug, $active_plugins ) ) {
		return '';
	}
	$norm_slug = (string) preg_replace( '/[^a-z0-9_\-]/i', '', strtolower( $slug ) );
	foreach ( fatalguard_recover_security_denylist() as $denied ) {
		if ( $norm_slug === (string) preg_replace( '/[^a-z0-9_\-]/i', '', strtolower( $denied ) ) ) {
			return '';
		}
	}
	return fatalguard_recover_link( $slug );
}

// Structured-channel seam: the Pro webhook dispatcher applies this filter to add a
// `recovery_url` to its JSON payload. Named to match the module's post-build filter
// (gutenx_debug_logs_recovery_url → fatalguard_recovery_url).
add_filter(
	'fatalguard_recovery_url',
	static function ( $url, $rec ) {
		if ( '' !== (string) $url ) {
			return $url;
		}
		return fatalguard_recover_url_for_record( is_array( $rec ) ? $rec : array() );
	},
	10,
	2
);

require_once __DIR__ . '/recover-screens.php';

// Run now — this file is included from the mu-plugin loader, before regular plugins.
fatalguard_recover_run();
