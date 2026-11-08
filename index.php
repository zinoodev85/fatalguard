<?php
/**
 * Debug Logs bootstrap.
 *
 * Admin-bar log viewer (WordPress / PHP / Fatals / Status tabs) +
 * fully-automatic file logging + PHP-fatal capture + Telegram alerting. Loads
 * unconditionally (a dev/ops tool, not an editor feature). Design + decisions:
 * fatalguard/src/debug-logs/concept.md.
 *
 * Self-installs a self-contained fatal-error drop-in that turns logging on and
 * captures fatals; it can never brick the site (the drop-in is fail-open and
 * self-disarms). Telegram creds come from the settings UI (constants).
 *
 * @package FatalGuard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/logging-state.php';
require_once __DIR__ . '/wp-config-writer.php';
require_once __DIR__ . '/hooks.php';
require_once __DIR__ . '/capture.php';
require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/reader.php';
require_once __DIR__ . '/grouping.php';
require_once __DIR__ . '/self-installer.php';
require_once __DIR__ . '/rest.php';
require_once __DIR__ . '/abilities.php'; // read-only data callbacks for FatalGuard's MCP tools
if ( is_file( __DIR__ . '/csp-report.php' ) ) { // fatalguard-only; standalone builds (FatalGuard) don't ship it
	require_once __DIR__ . '/csp-report.php';
}
require_once __DIR__ . '/admin.php';

// Load Pro modules if the bundle ships them (FatalGuard premium). The fatalguard
// context has no pro/ dir; the free wordpress.org bundle strips it. Each module
// hangs off the core hook seams and self-gates on fatalguard_can_use_pro().
if ( is_dir( __DIR__ . '/pro' ) ) {
	foreach ( (array) @glob( __DIR__ . '/pro/*.php' ) as $fatalguard_dl_pro_module ) {
		require_once $fatalguard_dl_pro_module;
	}
}

// Heal the boot artifacts at load. Wrapped so a fault in installation can never
// take fatalguard (or the site) down — worst case logging just isn't auto-enabled.
try {
	fatalguard_self_install();
} catch ( \Throwable $e ) {
	// Intentionally swallowed.
}

/**
 * Belt for when the drop-in is absent or the site disables WP's fatal handler:
 * assert file logging ourselves. Early (plugins_loaded:1) and again very late so
 * a stale sibling error_display.php (priority 1) can't win the last word.
 */
add_action( 'plugins_loaded', 'fatalguard_apply', 1 );
add_action( 'plugins_loaded', 'fatalguard_apply', PHP_INT_MAX );

// Admin-bar viewer. The bar (and our "Logs" node) shows on BOTH the front-end
// and wp-admin, so the assets + overlay must load in both contexts — otherwise
// the node renders but the click has no script/markup to act on. Each callback
// gates on the cap + is_admin_bar_showing(), and only logged-in (admin-bar)
// views are rendered, so the nonce never lands in publicly-cached HTML.
add_action( 'admin_bar_menu', 'fatalguard_admin_bar', 999 );
add_action( 'admin_enqueue_scripts', 'fatalguard_enqueue' );
add_action( 'wp_enqueue_scripts', 'fatalguard_enqueue' );
add_action( 'admin_footer', 'fatalguard_render_overlay' );
add_action( 'wp_footer', 'fatalguard_render_overlay' );

// REST API.
add_action( 'rest_api_init', 'fatalguard_register_rest' );

// Dispatch newly-captured fatals to listeners (Pro alert channels) in WP
// context — the pre-WP drop-in can't reach plugin-registered channels. No-op
// when nothing is listening (e.g. the free FatalGuard bundle with no pro/ dir).
// Belt-and-suspenders: also fire on plugins_loaded so a load-time fatal in
// ANOTHER plugin (which kills init) still dispatches on the next healthy request
// that reaches plugins_loaded. (B035)
// Not under WP-CLI: the sweep does blocking channel sends, which have no place
// running on every wp-cli command (and would make the marker/batch-cap behaviour
// non-deterministic in tests). Web requests are the delivery path. The sweep
// itself is guarded to run at most once per request (see the function), so
// registering on both hooks is a fallback, not a double-dispatch.
if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	add_action( 'plugins_loaded', 'fatalguard_sweep_new_fatals', 20 );
	add_action( 'init', 'fatalguard_sweep_new_fatals', 20 );
}
