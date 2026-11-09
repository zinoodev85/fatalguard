<?php
/**
 * Plugin Name:       FatalGuard
 * Plugin URI:        https://fatalguard.com/
 * Description:       View your debug log, catch fatal errors behind the white screen, and get instant Telegram alerts — no wp-config editing. Slack, email & Discord alerts in Pro.
 * Version:           1.3.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Zino (@zinodev)
 * Author URI:        https://profiles.wordpress.org/zinodev/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fatalguard
 * Domain Path:       /languages
 *
 * @package FatalGuard
 *
 * ---------------------------------------------------------------------------
 * This is the entry file for the plugin. It bootstraps the bundled FatalGuard
 * core module, bridging its one config coupling so no module edits are needed:
 *   Telegram creds: define FATALGUARD_TELEGRAM_* consts from our settings,
 *   which the module's constant-first resolver reads.
 * ---------------------------------------------------------------------------
 */

defined( 'ABSPATH' ) || exit;

// ── Free/premium collision guard ─────────────────────────────────────────────
// When WordPress' plugin sandbox loads the premium copy while the free copy is
// already active (or vice-versa), every unconditional top-level declaration in
// the file would produce a "Cannot redeclare" fatal before Freemius can deactivate
// the other version. The canonical Freemius dual-version pattern: detect that the
// plugin is already running, hand Freemius the new basename, and bail out cleanly.
if ( function_exists( 'fatalguard_bridge_settings' ) ) {
	if ( function_exists( 'fatalguard_fs' ) ) {
		fatalguard_fs()->set_basename( true, __FILE__ );
	}
	return;
}

define( 'FATALGUARD_VERSION', '1.3.2' );
define( 'FATALGUARD_FILE', __FILE__ );
define( 'FATALGUARD_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Bridge FatalGuard settings → the environment the (renamed) module expects.
 * Runs on plugin load, BEFORE the module's index.php is required below.
 */
if ( ! function_exists( 'fatalguard_bridge_settings' ) ) {
	function fatalguard_bridge_settings(): void {
		$opt = get_option( 'fatalguard_settings', array() );
		if ( ! is_array( $opt ) ) {
			$opt = array();
		}

		// 1. Telegram creds → constants (module resolves constant → env → file → empty).
		if ( ! defined( 'FATALGUARD_TELEGRAM_BOT_TOKEN' ) && ! empty( $opt['telegram_bot_token'] ) ) {
			define( 'FATALGUARD_TELEGRAM_BOT_TOKEN', (string) $opt['telegram_bot_token'] );
		}
		if ( ! defined( 'FATALGUARD_TELEGRAM_CHAT_ID' ) && ! empty( $opt['telegram_chat_id'] ) ) {
			define( 'FATALGUARD_TELEGRAM_CHAT_ID', (string) $opt['telegram_chat_id'] );
		}

		// (The former display-php-errors option mirror is gone: logging/display
		// are now WP_DEBUG_* constants in wp-config.php, written by the settings
		// toggles via the module's wp-config writer and applied by core.)
	}
}
fatalguard_bridge_settings();

// ── Freemius bootstrap ───────────────────────────────────────────────────────
// Dormant until the product exists: set the ID + Public Key from your Freemius
// product (Settings → Keys) and drop the SDK into freemius/. Until BOTH the keys
// are set AND freemius/start.php is present, this block is skipped entirely and
// the free feature set loads unconditionally — so it's safe to ship inert.
// See meta/freemius-setup.md. is_premium is derived from whether pro/ ships in the
// build (present = premium bundle, stripped = free wordpress.org bundle).
if ( ! function_exists( 'fatalguard_fs' ) ) {
	defined( 'FATALGUARD_FS_ID' )   || define( 'FATALGUARD_FS_ID', 37353 );                              // Freemius Plugin ID
	defined( 'FATALGUARD_FS_PKEY' ) || define( 'FATALGUARD_FS_PKEY', 'pk_723c4f4798f6cbe72f23d8a790923' ); // Freemius Public Key (safe to ship)

	if ( FATALGUARD_FS_ID && FATALGUARD_FS_PKEY && file_exists( FATALGUARD_DIR . 'freemius/start.php' ) ) {
		/** Freemius instance (singleton): licensing, upgrade flow, premium-code gating. */
		function fatalguard_fs() {
			global $fatalguard_fs;
			if ( isset( $fatalguard_fs ) ) {
				return $fatalguard_fs;
			}
			require_once FATALGUARD_DIR . 'freemius/start.php';
			$fatalguard_fs = fs_dynamic_init( array(
				'id'                  => FATALGUARD_FS_ID,
				'slug'                => 'fatalguard',
				'premium_slug'        => 'fatalguard-premium',
				'type'                => 'plugin',
				'public_key'          => FATALGUARD_FS_PKEY,
				'is_premium'          => is_dir( FATALGUARD_DIR . 'pro' ),
				'premium_suffix'      => 'Pro',
				'has_premium_version' => true,
				'has_paid_plans'      => true,
				'menu'                => array(
					'slug'    => 'fatalguard',
					'account' => true,
					'contact' => false,
					'support' => false,
				),
			) );
			do_action( 'fatalguard_fs_loaded' );
			return $fatalguard_fs;
		}
		fatalguard_fs();
		// B208: suppress the post-activation opt-in redirect so users installing
		// FatalGuard during an emergency land directly on the Alerts settings page.
		fatalguard_fs()->add_filter( 'redirect_on_activation', '__return_false' );
	}
}

/**
 * Map the module's Pro capability gate to the FatalGuard license. The core
 * module defaults the gate to TRUE (all features on); this filter gates it to
 * the license so the free wordpress.org build runs free features only and Pro
 * unlocks the pro/ modules.
 *
 * Freemius-first: once bootstrapped, can_use_premium_code() is the source of
 * truth (true = premium code present AND an active license / trial / dev sandbox).
 * The pro_license settings flag is the local/dev fallback so e2e + local testing
 * work without a license. The free bundle also physically strips pro/
 * (product.json premiumOnly), so on free installs there's nothing to gate — this
 * is the belt for the premium bundle.
 */
if ( ! function_exists( 'fatalguard_is_pro' ) ) {
	function fatalguard_is_pro(): bool {
		// Explicit dev/test override, ONLY when FATALGUARD_DEV is defined (e2e + local).
		// Gating it on the constant stops a dev-DB import from making the pro_license key
		// sticky on production — otherwise `=1` would unlock a free user and `=0` would
		// lock out a paying one. Real installs never define FATALGUARD_DEV, so the key is
		// ignored there and the Freemius license is the sole source of truth.
		if ( defined( 'FATALGUARD_DEV' ) && FATALGUARD_DEV ) {
			$opt = get_option( 'fatalguard_settings', array() );
			if ( is_array( $opt ) && array_key_exists( 'pro_license', $opt ) ) {
				return ! empty( $opt['pro_license'] );
			}
		}
		if ( function_exists( 'fatalguard_fs' ) ) {
			// AND the license with the presence of pro/: a real trial or a
			// license-activated-but-still-on-the-free-bundle install must NOT render
			// live-but-inert Pro UI (dead Slack/webhook fields, blank AI panel) for a
			// paying user. The premium bundle ships pro/; the free wordpress.org zip
			// strips it, so there is nothing to unlock there.
			return (bool) fatalguard_fs()->can_use_premium_code() && is_dir( FATALGUARD_DIR . 'pro' );
		}
		return false;
	}
}
add_filter( 'fatalguard_can_use_pro', 'fatalguard_is_pro' );

// Translations: the free wordpress.org build needs NO load_plugin_textdomain — since
// WP 4.6 core auto-loads a hosted plugin's translations by slug (just-in-time). Only the
// Freemius-distributed Pro build (not wp.org-hosted, so it isn't auto-translated) needs to
// register its bundled languages/*.mo — and that call lives in pro/i18n.php, which is
// physically stripped from the free zip (product.json premiumOnly), so the free bundle
// ships zero load_plugin_textdomain calls.
if ( is_readable( FATALGUARD_DIR . 'pro/i18n.php' ) ) {
	require_once FATALGUARD_DIR . 'pro/i18n.php';
}

// Uninstall cleanup. Freemius intercepts WordPress' native uninstall, so a root
// uninstall.php is disallowed with the SDK — the cleanup runs on after_uninstall.
require_once FATALGUARD_DIR . 'uninstall-cleanup.php';
if ( function_exists( 'fatalguard_fs' ) ) {
	fatalguard_fs()->add_action( 'after_uninstall', 'fatalguard_uninstall_cleanup' );
} else {
	// B116: Freemius inert (SDK missing) — fall back to WP's native uninstall hook
	// so data is still cleaned up on delete instead of leaking silently.
	register_uninstall_hook( __FILE__, 'fatalguard_uninstall_cleanup' );
}

// Standalone settings page (Telegram token + chat id + logging toggles).
require_once FATALGUARD_DIR . 'settings/settings-page.php';

// Recovery: install the mu-loader that boots the one-tap recovery engine before
// regular plugins (so a fatal plugin can still be switched off from an alert link).
require_once FATALGUARD_DIR . 'recover/setup.php';

// Boot the extracted module (self-installs the drop-in, registers hooks).
require_once FATALGUARD_DIR . 'index.php';

// AI access: FatalGuard's own read-only MCP endpoint (free; Pro adds the history tool).
require_once FATALGUARD_DIR . 'mcp.php';

/**
 * Deactivation: pull every executing artifact so NO FatalGuard code runs once
 * the plugin is off (a deactivated plugin that keeps executing is a wp.org
 * bounce). Removes the fatal-error drop-in + the recovery mu-loader. Deliberately
 * does NOT delete data — options and captured fatals survive deactivate/reactivate;
 * data removal lives in uninstall.php (fires on delete).
 */
if ( ! function_exists( 'fatalguard_on_deactivate' ) ) {
	function fatalguard_on_deactivate(): void {
		if ( function_exists( 'fatalguard_remove_dropin' ) ) {
			fatalguard_remove_dropin();
		}
		if ( function_exists( 'fatalguard_recover_uninstall_loader' ) ) {
			fatalguard_recover_uninstall_loader();
		}
		// Clear our wp-cron events so nothing is scheduled while the plugin is off.
		wp_clear_scheduled_hook( defined( 'FATALGUARD_DL_DIGEST_HOOK' ) ? FATALGUARD_DL_DIGEST_HOOK : 'fatalguard_weekly_digest' );
		wp_clear_scheduled_hook( 'fatalguard_pro_history_prune' );
		wp_clear_scheduled_hook( 'fatalguard_telegram_healthcheck' );
	}
}
register_deactivation_hook( FATALGUARD_FILE, 'fatalguard_on_deactivate' );
