<?php
/**
 * FatalGuard settings page. Stores everything in the single `fatalguard_settings`
 * option; fatalguard.php bridges those values to the core module on load.
 *
 * The screen is custom-rendered (card layout, not the default WP settings tables)
 * so it reads as one product. Free = Telegram alerts + logging. The Pro channels
 * show as LOCKED teaser cards — an upsell, never a functional-but-dead field and
 * never a self-serve unlock (both bar wp.org review). Real Pro gating arrives with
 * Freemius (fatalguard_fs()->can_use_premium_code()).
 *
 * @package FatalGuard
 */

defined( 'ABSPATH' ) || exit;

const FATALGUARD_SETTINGS_OPTION = 'fatalguard_settings';

// Runtime bridge: saved settings → the Pro modules' config filters. Always on.
require_once __DIR__ . '/pro-config.php';

// Telegram connect wizard (REST routes + connect.js asset). Always on so the
// "Alerts" popup tab works even when the settings screen isn't open.
require_once __DIR__ . '/telegram-connect.php';
// Translations for fatalguard-connect (connect.js). The actual registration lives in
// fatalguard_connect_enqueue() (telegram-connect.php); declared here too for discoverability.
// wp_set_script_translations( 'fatalguard-connect', 'fatalguard' ) — see telegram-connect.php

// Dashboard widget: latest fatal + alert status + Pro nudge.
require_once __DIR__ . '/dashboard-widget.php';

// Inline SVG icon set (fatalguard_icon()).
require_once __DIR__ . '/icons.php';

// Dismissible "leave a review" prompt (after first fatal / two weeks).
require_once __DIR__ . '/review-ask.php';

/** Register FatalGuard as a TOP-LEVEL admin menu (not buried under Settings). */
function fatalguard_settings_menu(): void {
	add_menu_page(
		__( 'FatalGuard', 'fatalguard' ),
		__( 'FatalGuard', 'fatalguard' ),
		'manage_options',
		'fatalguard',
		'fatalguard_settings_render',
		'dashicons-shield-alt',
		80
	);
}
add_action( 'admin_menu', 'fatalguard_settings_menu' );

/** True when Pro stealth mode should hide FatalGuard from the CURRENT admin. */
function fatalguard_stealth_hidden(): bool {
	return function_exists( 'fatalguard_pro_stealth_active' ) && fatalguard_pro_stealth_active();
}

/**
 * Stealth mode, hardened: hiding the plugins-list row + admin-bar node is not
 * enough — a non-allowlisted admin could still reach admin.php?page=fatalguard and
 * simply add their own email to the allowlist, permanently defeating stealth. Remove
 * the menu AND hard-block the screen. Runs late so the menu exists to remove.
 */
function fatalguard_stealth_hide_menu(): void {
	if ( fatalguard_stealth_hidden() ) {
		remove_menu_page( 'fatalguard' );
	}
}
add_action( 'admin_menu', 'fatalguard_stealth_hide_menu', 999 );

/**
 * Guarantee a page title on our top-level screen. WordPress core strips the
 * $title global (wp-admin/admin-header.php: `$title = strip_tags( $title )`);
 * if get_admin_page_title() leaves it null, PHP 8.1+ warns "strip_tags(): Passing
 * null". `current_screen` fires from set_current_screen() BEFORE that strip, and
 * get_admin_page_title() returns early when $title is already set — so filling it
 * here removes the deprecation for good. No-op on every other screen.
 */
function fatalguard_settings_fix_title( $screen ): void {
	global $title;
	if ( $screen && 'toplevel_page_fatalguard' === $screen->id && ( null === $title || '' === $title ) ) {
		$title = __( 'FatalGuard', 'fatalguard' );
	}
}
add_action( 'current_screen', 'fatalguard_settings_fix_title' );

/** The admin URL of the FatalGuard screen (top-level, so admin.php not options-general.php). */
function fatalguard_settings_page_url(): string {
	return admin_url( 'admin.php?page=fatalguard' );
}

/** Register the option (save + nonce via the Settings API). Fields are rendered by hand. */
function fatalguard_settings_init(): void {
	register_setting(
		'fatalguard',
		FATALGUARD_SETTINGS_OPTION,
		array( 'sanitize_callback' => 'fatalguard_settings_sanitize' )
	);
}
add_action( 'admin_init', 'fatalguard_settings_init' );

/** Load the settings stylesheet only on our screen. */
function fatalguard_settings_assets( $hook ): void {
	if ( 'toplevel_page_fatalguard' !== $hook ) {
		return;
	}
	wp_enqueue_style(
		'fatalguard-settings',
		plugins_url( 'assets/settings.css', FATALGUARD_FILE ),
		array(),
		FATALGUARD_VERSION
	);
	wp_enqueue_script(
		'fatalguard-settings-ui',
		plugins_url( 'assets/settings-ui.js', FATALGUARD_FILE ),
		array(),
		FATALGUARD_VERSION,
		true
	);
	wp_localize_script(
		'fatalguard-settings-ui',
		'fatalguardSettingsL10n',
		array(
			'sending' => __( 'Sending…', 'fatalguard' ),
			'none'    => __( 'No channels configured yet.', 'fatalguard' ),
			'failed'  => __( 'Request failed.', 'fatalguard' ),
			'copied'  => __( 'Copied ✓', 'fatalguard' ),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'fatalguard_settings_assets' );

/**
 * Warn the admin when Pro alert deliveries have been failing — otherwise a dead or
 * misconfigured webhook drops fatals silently, which is the whole thing this plugin
 * exists to prevent. The failure log is written by the channels module.
 */
function fatalguard_delivery_failures_to_show(): array {
	if ( ! current_user_can( 'manage_options' ) ) {
		return array();
	}
	// B076/B038: Stealth mode must suppress this notice for non-allowlisted admins.
	if ( fatalguard_stealth_hidden() ) {
		return array();
	}
	$fails = get_option( 'fatalguard_delivery_failures', array() );
	if ( ! is_array( $fails ) || empty( $fails ) ) {
		return array();
	}
	// Guideline 11 (moderation): the native X persists the dismissal for THIS exact
	// failure set; any NEW failure changes the hash and the notice comes back.
	if ( get_option( 'fatalguard_delivery_failures_dismissed' ) === fatalguard_delivery_failures_hash( $fails ) ) {
		return array();
	}
	return $fails;
}

/** Stable fingerprint of a failure set (stored on dismiss, compared on render). */
function fatalguard_delivery_failures_hash( array $fails ): string {
	return md5( (string) wp_json_encode( $fails ) );
}

function fatalguard_delivery_failure_notice(): void {
	$fails = fatalguard_delivery_failures_to_show();
	if ( empty( $fails ) ) {
		return;
	}
	// B075: aggregate per channel — newest entry per distinct channel label.
	$by_channel = array();
	foreach ( $fails as $entry ) {
		$ch = (string) ( $entry['channel'] ?? '' );
		if ( ! isset( $by_channel[ $ch ] ) ) {
			$by_channel[ $ch ] = $entry; // newest first, so first seen = newest per channel.
		}
	}
	$latest = reset( $by_channel );
	$settings_url = fatalguard_settings_page_url() . '#alerts';
	$msg          = sprintf(
		/* translators: 1: count, 2: channel, 3: host, 4: reason, 5: settings URL */
		_n(
			'FatalGuard: %1$d alert delivery failed. Most recent: %2$s → %3$s (%4$s). <a href="%5$s">Check the channel settings.</a>',
			'FatalGuard: %1$d alert deliveries failed. Most recent: %2$s → %3$s (%4$s). <a href="%5$s">Check the channel settings.</a>',
			count( $fails ),
			'fatalguard'
		),
		count( $fails ),
		esc_html( (string) ( $latest['channel'] ?? '' ) ),
		esc_html( (string) ( $latest['host'] ?? '' ) ),
		esc_html( (string) ( $latest['detail'] ?? '' ) ),
		esc_url( $settings_url )
	);
	echo '<div class="notice notice-error is-dismissible fg-delivery-fail"><p>' . wp_kses_post( $msg ) . '</p></div>';
}
add_action( 'admin_notices', 'fatalguard_delivery_failure_notice' );

/** Persist an X-dismissal of the delivery-failure notice (nonced, handled before output). */
add_action( 'admin_init', 'fatalguard_delivery_failure_dismiss' );
function fatalguard_delivery_failure_dismiss(): void {
	if ( ! isset( $_GET['fatalguard_failures_dismiss'] ) || ! check_admin_referer( 'fatalguard_failures_dismiss' ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$fails = get_option( 'fatalguard_delivery_failures', array() );
	if ( is_array( $fails ) && ! empty( $fails ) ) {
		update_option( 'fatalguard_delivery_failures_dismissed', fatalguard_delivery_failures_hash( $fails ), false );
	}
	wp_safe_redirect( remove_query_arg( array( 'fatalguard_failures_dismiss', '_wpnonce' ) ) );
	exit;
}

/** Enqueue the X-persistence script wherever the notice will render (any admin page). */
add_action( 'admin_enqueue_scripts', 'fatalguard_delivery_failure_assets' );
function fatalguard_delivery_failure_assets(): void {
	if ( empty( fatalguard_delivery_failures_to_show() ) ) {
		return;
	}
	wp_enqueue_script(
		'fatalguard-delivery-fail',
		plugins_url( 'assets/delivery-fail.js', FATALGUARD_FILE ),
		array(),
		FATALGUARD_VERSION,
		true
	);
	wp_localize_script(
		'fatalguard-delivery-fail',
		'fatalguardDeliveryFailL10n',
		array(
			'dismissUrl' => wp_nonce_url( add_query_arg( 'fatalguard_failures_dismiss', 1 ), 'fatalguard_failures_dismiss' ),
		)
	);
}

/**
 * Clear delivery failures ONLY for channels whose credential fields actually changed
 * between the old and new settings (B032). A blanket delete_option on every save
 * would silence genuinely-dead channels; per-channel diffing keeps those flagged
 * while dismissing the ones the admin just reconfigured. Called directly from the
 * sanitize callback (B073) so even a same-value re-save runs the diff, and also
 * wired to update_option_ so a programmatic change is covered.
 *
 * Mapping (credential field → channel label stored in fatalguard_delivery_failures).
 */
function fatalguard_clear_delivery_failures( $old_value = null, $new_value = null ): void {
	if ( ! function_exists( 'get_option' ) ) {
		return;
	}
	// No-arg call = explicit user dismiss (e.g. after a successful "Send test"):
	// wipe all recorded failures. With old/new supplied (save path), diff per channel.
	if ( null === $old_value && null === $new_value ) {
		delete_option( 'fatalguard_delivery_failures' );
		delete_option( 'fatalguard_delivery_failures_dismissed' );
		return;
	}
	$old = is_array( $old_value ) ? $old_value : array();
	$new = is_array( $new_value ) ? $new_value : array();

	$channel_fields = array(
		'Telegram' => array( 'telegram_bot_token', 'telegram_chat_id' ),
		'Slack'    => array( 'slack_webhook' ),
		'Discord'  => array( 'discord_webhook' ),
		'Webhook'  => array( 'webhook' ),
		'Email'    => array( 'alert_email' ),
	);

	$changed_channels = array();
	foreach ( $channel_fields as $channel => $fields ) {
		foreach ( $fields as $field ) {
			if ( ( $old[ $field ] ?? '' ) !== ( $new[ $field ] ?? '' ) ) {
				$changed_channels[] = $channel;
				break;
			}
		}
	}

	if ( empty( $changed_channels ) ) {
		return; // No credential changed — preserve ALL failure records.
	}

	$failures = get_option( 'fatalguard_delivery_failures', array() );
	if ( ! is_array( $failures ) || empty( $failures ) ) {
		return;
	}
	$kept = array_filter(
		$failures,
		function ( $entry ) use ( $changed_channels ) {
			$entry_channel = isset( $entry['channel'] ) ? (string) $entry['channel'] : '';
			return ! in_array( $entry_channel, $changed_channels, true );
		}
	);
	if ( empty( $kept ) ) {
		delete_option( 'fatalguard_delivery_failures' );
	} else {
		update_option( 'fatalguard_delivery_failures', array_values( $kept ), false );
	}
}
add_action( 'update_option_' . FATALGUARD_SETTINGS_OPTION, 'fatalguard_clear_delivery_failures', 10, 2 );

/**
 * Sync the WP_DEBUG_* constants in wp-config.php with the logging/display
 * toggles whenever the settings are saved — an explicit user action, the same
 * review-approved mechanism Debug Log Manager uses. The plugin itself never
 * calls ini_set()/error_reporting(); core applies these constants.
 */
function fatalguard_wpconfig_apply_settings( $old, $new ): void {
	if ( ! function_exists( 'fatalguard_wpconfig_sync' ) ) {
		return;
	}
	$old = is_array( $old ) ? $old : array();
	$new = is_array( $new ) ? $new : array();

	$log     = ! empty( $new['file_logging'] );
	$display = ! empty( $new['display_php_errors'] );
	if ( ! empty( $old['file_logging'] ) === $log && ! empty( $old['display_php_errors'] ) === $display ) {
		return; // Toggles unchanged — leave wp-config.php alone.
	}

	$result = fatalguard_wpconfig_sync( $log, $display );
	if ( true === $result ) {
		delete_option( 'fatalguard_wpconfig_error' );
		return;
	}
	// Remember the failure so the settings screen can show the manual snippet.
	update_option( 'fatalguard_wpconfig_error', (string) $result, false );
	add_settings_error(
		FATALGUARD_SETTINGS_OPTION,
		'fatalguard_wpconfig',
		sprintf(
			/* translators: %s: the reason wp-config.php could not be updated. */
			__( 'FatalGuard could not update wp-config.php (%s). Add the lines shown under Logging manually, or fix the file permissions and save again.', 'fatalguard' ),
			esc_html( (string) $result )
		),
		'error'
	);
}
add_action( 'update_option_' . FATALGUARD_SETTINGS_OPTION, 'fatalguard_wpconfig_apply_settings', 20, 2 );
add_action( 'add_option_' . FATALGUARD_SETTINGS_OPTION, function ( $name, $value ): void {
	fatalguard_wpconfig_apply_settings( array(), $value );
}, 20, 2 );

/**
 * The log viewer's bottom-bar "Log PHP errors" checkbox (module REST
 * /file-logging) — handled here so the settings option, this screen's checkbox
 * and wp-config.php all stay in sync. Runs the sync directly (not only via the
 * option hook) so a manual wp-config edit can't strand the option out of step.
 */
add_filter( 'fatalguard_set_file_logging', function ( $handled, bool $enabled ) {
	$o = get_option( FATALGUARD_SETTINGS_OPTION, array() );
	$o = is_array( $o ) ? $o : array();
	$o['file_logging'] = $enabled ? 1 : 0;
	update_option( FATALGUARD_SETTINGS_OPTION, $o ); // fires the sync hook when changed
	$result = fatalguard_wpconfig_sync( $enabled, ! empty( $o['display_php_errors'] ) ); // idempotent re-run covers the unchanged-option edge
	if ( true === $result ) {
		delete_option( 'fatalguard_wpconfig_error' );
		return true;
	}
	update_option( 'fatalguard_wpconfig_error', (string) $result, false );
	return (string) $result;
}, 10, 2 );

/**
 * Under the Logging toggles: current wp-config manageability + the manual
 * snippet when the last sync failed (Error Log Monitor's fallback pattern).
 */
function fatalguard_wpconfig_notice_render(): void {
	if ( ! function_exists( 'fatalguard_wpconfig_writable' ) ) {
		return;
	}
	$error = (string) get_option( 'fatalguard_wpconfig_error', '' );
	if ( '' === $error && fatalguard_wpconfig_writable() ) {
		return;
	}
	$log     = '' !== fatalguard_settings_val( 'file_logging' ) && fatalguard_settings_val( 'file_logging' );
	$display = '' !== fatalguard_settings_val( 'display_php_errors' ) && fatalguard_settings_val( 'display_php_errors' );
	echo '<div class="fg-card fg-card--warn">';
	echo '<p>' . esc_html__( 'wp-config.php is not writable, so FatalGuard cannot apply these toggles for you. Add the following lines above the "stop editing" comment in wp-config.php:', 'fatalguard' ) . '</p>';
	echo '<pre><code>' . esc_html( fatalguard_wpconfig_snippet( $log, $display ) ) . '</code></pre>';
	echo '</div>';
}

/**
 * AJAX: dismiss all delivery-failure warnings. Fired by the "Send test to all
 * channels" success path (B073) — a successful live test proves delivery works,
 * so the stale red notice should clear without waiting for the health cron.
 */
function fatalguard_ajax_dismiss_failures(): void {
	if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'fatalguard_dismiss', false, false ) ) {
		wp_send_json_error( array(), 403 );
	}
	fatalguard_clear_delivery_failures();
	wp_send_json_success();
}
add_action( 'wp_ajax_fatalguard_dismiss_failures', 'fatalguard_ajax_dismiss_failures' );

/**
 * Sanitize + persist. Starts from the previous value so keys this screen does not
 * render (e.g. future Freemius Pro fields) are preserved, never clobbered.
 */
function fatalguard_settings_sanitize( $input ): array {
	// B154: WP double-sanitizes on the first-ever save (update_option → add_option
	// path) with the SAME input, which would add duplicate settings errors. Dedupe
	// ONLY that genuine double-call by keying on the input signature — so distinct
	// programmatic calls (batch flows, e2e specs exercising several fields in one
	// process) each still process instead of returning a stale option.
	$input = is_array( $input ) ? $input : array();
	static $last_sig = null;
	$sig = md5( (string) wp_json_encode( $input ) );
	if ( null !== $last_sig && $last_sig === $sig ) {
		return (array) get_option( FATALGUARD_SETTINGS_OPTION, array() );
	}
	$last_sig = $sig;
	$out   = get_option( FATALGUARD_SETTINGS_OPTION, array() );
	$out   = is_array( $out ) ? $out : array();
	// B032/B073: snapshot the previous settings so the per-channel clear (fired
	// before return) dismisses failures only for channels reconfigured this save.
	$fatalguard_prev_settings = $out;

	// Bot token is masked (never echoed) → a blank submit means "keep the saved one".
	$token = isset( $input['telegram_bot_token'] ) ? sanitize_text_field( $input['telegram_bot_token'] ) : '';
	if ( '' !== $token ) {
		$out['telegram_bot_token'] = $token;
	}
	if ( isset( $input['telegram_chat_id'] ) ) {
		$new_chat = sanitize_text_field( $input['telegram_chat_id'] );
		if ( '' === $new_chat ) {
			// Cleared on purpose — accept.
			if ( $new_chat !== ( $out['telegram_chat_id'] ?? '' ) ) {
				$out['telegram_chat_title']    = '';
				$out['telegram_chat_username'] = '';
			}
			$out['telegram_chat_id'] = '';
		} elseif ( preg_match( '/^-?\d{3,}$/', $new_chat ) ) {
			// Valid Telegram chat ID: numeric, optional leading minus, at least 3 digits.
			if ( $new_chat !== ( $out['telegram_chat_id'] ?? '' ) ) {
				$out['telegram_chat_title']    = '';
				$out['telegram_chat_username'] = '';
			}
			$out['telegram_chat_id'] = $new_chat;
		} else {
			// Invalid format — keep previous value and surface the error.
			add_settings_error(
				FATALGUARD_SETTINGS_OPTION,
				'fatalguard_bad_chat_id',
				sprintf(
					/* translators: %s: the rejected chat ID value */
					__( '"%s" is not a valid Telegram chat ID (must be a number like -100123456789) — keeping the previous value.', 'fatalguard' ),
					esc_html( $new_chat )
				),
				'error'
			);
			// leave $out['telegram_chat_id'] as the previously-saved value
		}
	}
	$out['file_logging']       = empty( $input['file_logging'] ) ? 0 : 1;
	$out['display_php_errors'] = empty( $input['display_php_errors'] ) ? 0 : 1;

	// Pro fields — only submitted on Pro installs (rendered behind can_use_pro);
	// each isset-guarded so free saves never touch them, and validated by type.
	if ( isset( $input['history_retention_days'] ) ) {
		$raw_ret = trim( (string) $input['history_retention_days'] );
		if ( '' === $raw_ret ) {
			// Blank = "reset to default" — drop the key so the 90-day default re-applies.
			unset( $out['history_retention_days'] );
		} elseif ( is_numeric( $raw_ret ) ) {
			// Explicit numeric value, clamped to [0, 3650] at BOTH ends: 0 is the
			// keep-forever sentinel, a negative is nonsensical (→ 0), an over-max
			// value is capped (→ 3650). Symmetric clamping = predictable behaviour.
			$out['history_retention_days'] = max( 0, min( 3650, (int) $raw_ret ) );
		} else {
			// Non-numeric garbage — keep the previous value and tell the admin.
			add_settings_error(
				FATALGUARD_SETTINGS_OPTION,
				'fatalguard_bad_retention',
				sprintf(
					/* translators: %s: the rejected value */
					__( '"%s" is not a valid number of days — keeping the previous value.', 'fatalguard' ),
					esc_html( $raw_ret )
				),
				'error'
			);
		}
	}
	foreach ( array( 'slack_webhook', 'discord_webhook', 'webhook' ) as $u ) {
		if ( ! isset( $input[ $u ] ) ) {
			continue;
		}
		$raw_url = trim( (string) $input[ $u ] );
		if ( '' === $raw_url ) {
			$out[ $u ] = ''; // cleared on purpose
		} elseif ( wp_http_validate_url( $raw_url ) && 'https' === wp_parse_url( $raw_url, PHP_URL_SCHEME ) ) {
			$out[ $u ] = esc_url_raw( $raw_url );
		} else {
			// Invalid or non-https URL — keep previous value and notify the admin.
			add_settings_error(
				FATALGUARD_SETTINGS_OPTION,
				'fatalguard_bad_webhook_' . $u,
				sprintf(
					/* translators: %s: the rejected URL */
					__( '"%s" is not a valid HTTPS webhook URL — keeping the previous value.', 'fatalguard' ),
					esc_html( $raw_url )
				),
				'error'
			);
			// leave $out[$u] as the previously-saved value
		}
	}
	foreach ( array( 'alert_email', 'digest_recipient' ) as $e ) {
		if ( ! isset( $input[ $e ] ) ) {
			continue;
		}
		$raw  = trim( (string) $input[ $e ] );
		$mail = sanitize_email( $raw );
		if ( '' === $raw ) {
			$out[ $e ] = ''; // cleared on purpose
		} elseif ( '' !== $mail && is_email( $mail ) ) {
			$out[ $e ] = $mail;
		} else {
			// Invalid, non-empty: KEEP the previous value + tell the user, instead of
			// silently discarding it to '' under a "Settings saved." notice (which
			// would leave fatals never emailed).
			add_settings_error(
				FATALGUARD_SETTINGS_OPTION,
				'fatalguard_bad_email_' . $e,
				sprintf(
					/* translators: %s: the rejected email address */
					__( '“%s” is not a valid email address — keeping the previous value.', 'fatalguard' ),
					esc_html( $raw )
				),
				'error'
			);
			// leave $out[$e] as the previously-saved value (already in $out)
		}
	}
	// telegram_targets: free-form, sanitize only.
	if ( isset( $input['telegram_targets'] ) ) {
		$out['telegram_targets'] = sanitize_textarea_field( (string) $input['telegram_targets'] );
	}

	// B036: stealth_allowlist — validate each entry as an email, and ensure the
	// saving admin's own email is always in the list (a typo or label prefix must
	// not lock them out of the settings screen permanently).
	if ( isset( $input['stealth_allowlist'] ) ) {
		$raw_list = sanitize_textarea_field( (string) $input['stealth_allowlist'] );
		$entries  = preg_split( '/[\s,]+/', $raw_list, -1, PREG_SPLIT_NO_EMPTY );
		$valid    = array();
		$invalid  = array();
		foreach ( $entries as $entry ) {
			if ( is_email( $entry ) ) {
				$valid[] = sanitize_email( $entry );
			} else {
				$invalid[] = $entry;
			}
		}
		if ( ! empty( $invalid ) ) {
			add_settings_error(
				FATALGUARD_SETTINGS_OPTION,
				'fatalguard_bad_stealth_email',
				sprintf(
					/* translators: %s: comma-separated list of rejected entries */
					__( 'Stealth allowlist: these entries are not valid email addresses and were removed — %s', 'fatalguard' ),
					esc_html( implode( ', ', $invalid ) )
				),
				'warning'
			);
		}
		// Self-lockout guard: if the list is non-empty but excludes the current
		// admin's email, auto-append it so they cannot accidentally lock themselves out.
		if ( ! empty( $valid ) ) {
			$current_email = strtolower( wp_get_current_user()->user_email ?? '' );
			$lower_list    = array_map( 'strtolower', $valid );
			if ( '' !== $current_email && ! in_array( $current_email, $lower_list, true ) ) {
				$valid[] = sanitize_email( $current_email );
				add_settings_error(
					FATALGUARD_SETTINGS_OPTION,
					'fatalguard_stealth_self_added',
					sprintf(
						/* translators: %s: the current admin's email address */
						__( 'Your email (%s) was automatically added to the stealth allowlist — removing yourself would lock you out of FatalGuard settings.', 'fatalguard' ),
						esc_html( $current_email )
					),
					'warning'
				);
			}
		}
		$out['stealth_allowlist'] = implode( "\n", $valid );
	}

	// B073/B032: dismiss delivery-failure notices for channels whose credentials
	// changed this save (per-channel, not a blanket wipe).
	fatalguard_clear_delivery_failures( $fatalguard_prev_settings, $out );

	return $out;
}

/** A saved-settings value, always a string. */
function fatalguard_settings_val( string $key ): string {
	$opt = get_option( FATALGUARD_SETTINGS_OPTION, array() );
	return is_array( $opt ) && isset( $opt[ $key ] ) ? (string) $opt[ $key ] : '';
}

/**
 * True only when FatalGuard is ACTUALLY capturing fatals right now:
 *   • our drop-in is installed (not absent, not a foreign handler)
 *   • the DISABLE kill-switch is not set
 *   • WordPress's fatal handler is enabled (not WP_DISABLE_FATAL_ERROR_HANDLER)
 *
 * Uses both the fatalguard_* (dist) and gutenx_debug_logs_* (dev) variants so
 * this helper works in both runtimes. Builds on B101 work; extends it with the
 * DISABLE and fatal_handler_enabled guards.
 *
 * @since 1.2.0
 */
function fatalguard_capture_active(): bool {
	// Drop-in ownership — check fatalguard_dropin_is_ours (dist) or gutenx_debug_logs_dropin_is_ours (dev).
	$dropin_ours = ( function_exists( 'fatalguard_dropin_is_ours' ) && fatalguard_dropin_is_ours() )
		|| ( function_exists( 'gutenx_debug_logs_dropin_is_ours' ) && gutenx_debug_logs_dropin_is_ours() );
	if ( ! $dropin_ours ) {
		return false;
	}
	// DISABLE kill-switch — written by the drop-in on a handler fault (auto-disarm).
	$disabled = ( function_exists( 'fatalguard_is_disabled' ) && fatalguard_is_disabled() )
		|| ( function_exists( 'gutenx_debug_logs_is_disabled' ) && gutenx_debug_logs_is_disabled() );
	if ( $disabled ) {
		return false;
	}
	// WP fatal handler enabled (WP_DISABLE_FATAL_ERROR_HANDLER / wp_fatal_error_handler_enabled filter).
	$handler_ok = function_exists( 'wp_is_fatal_error_handler_enabled' )
		? wp_is_fatal_error_handler_enabled()
		: ! ( defined( 'WP_DISABLE_FATAL_ERROR_HANDLER' ) && WP_DISABLE_FATAL_ERROR_HANDLER );
	return $handler_ok;
}

/**
 * True when at least one alert destination is configured.
 * Checks Telegram creds (saved option OR wp-config constants), and when Pro is
 * active also checks Slack/Discord/generic webhook/email channels.
 *
 * @since 1.2.0
 */
function fatalguard_any_channel_active(): bool {
	$opt = get_option( FATALGUARD_SETTINGS_OPTION, array() );
	$opt = is_array( $opt ) ? $opt : array();

	// Telegram: saved creds OR constants defined in wp-config (FATALGUARD_TELEGRAM_TOKEN / _CHAT_ID).
	$has_tg_token = ! empty( $opt['telegram_bot_token'] )
		|| ( defined( 'FATALGUARD_TELEGRAM_BOT_TOKEN' ) && '' !== FATALGUARD_TELEGRAM_BOT_TOKEN );
	$has_tg_chat  = ! empty( $opt['telegram_chat_id'] )
		|| ( defined( 'FATALGUARD_TELEGRAM_CHAT_ID' ) && '' !== FATALGUARD_TELEGRAM_CHAT_ID );
	if ( $has_tg_token && $has_tg_chat ) {
		return true;
	}

	// Pro channels (Slack / Discord / generic webhook / email) — only meaningful on Pro.
	if ( function_exists( 'fatalguard_can_use_pro' ) && fatalguard_can_use_pro() ) {
		if ( ! empty( $opt['slack_webhook'] ) ) {
			return true;
		}
		if ( ! empty( $opt['discord_webhook'] ) ) {
			return true;
		}
		if ( ! empty( $opt['webhook'] ) ) {
			return true;
		}
		if ( ! empty( $opt['alert_email'] ) && is_email( (string) $opt['alert_email'] ) ) {
			return true;
		}
	}

	return false;
}

/** Render the whole screen. */
function fatalguard_settings_render(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// Stealth: a non-allowlisted admin must not reach the screen even by direct URL
	// (removing the menu alone leaves it navigable, and the allowlist is editable
	// from here — a self-unlock). Hard-block.
	if ( fatalguard_stealth_hidden() ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'fatalguard' ), 403 );
	}
	$connected = fatalguard_any_channel_active();
	$opt_name  = esc_attr( FATALGUARD_SETTINGS_OPTION );
	// Keep $has_token / $has_chat for the Telegram wizard placeholders below.
	$has_token = '' !== fatalguard_settings_val( 'telegram_bot_token' );
	$has_chat  = '' !== fatalguard_settings_val( 'telegram_chat_id' );
	$display   = '' !== fatalguard_settings_val( 'display_php_errors' ) && fatalguard_settings_val( 'display_php_errors' );

	echo '<div class="wrap fatalguard-settings">';
	echo '<h1 class="fg-hide">' . esc_html__( 'FatalGuard', 'fatalguard' ) . '</h1>';
	settings_errors();

	// Header band — identity + a live status readout (three signals, like a monitor).
	$fatals   = function_exists( 'fatalguard_read_fatals' ) ? fatalguard_read_fatals( 200 ) : array();
	$fatal_n  = count( $fatals );
	$last_ts  = 0;
	foreach ( $fatals as $f ) {
		$t = isset( $f['time'] ) ? strtotime( (string) $f['time'] ) : 0;
		if ( $t && $t > $last_ts ) { $last_ts = $t; }
	}

	echo '<div class="fg-head">';
	echo '<div class="fg-mark" aria-hidden="true"><svg viewBox="0 0 24 24" width="38" height="38"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z" fill="#f5a623"/><rect x="11.1" y="7.4" width="1.8" height="5.9" rx=".9" fill="#231800"/><circle cx="12" cy="15.9" r="1.12" fill="#231800"/></svg></div>';
	echo '<div class="fg-head__id"><h1>' . esc_html__( 'FatalGuard', 'fatalguard' ) . '</h1>';
	echo '<p>' . esc_html__( 'Catches the fatal error behind the white screen — and pings you the moment one hits.', 'fatalguard' ) . '</p></div>';

	// Determine the label for the Armed/Standby badge when $connected.
	$channel_label = __( 'telegram', 'fatalguard' );
	if ( $connected ) {
		$_opt = get_option( FATALGUARD_SETTINGS_OPTION, array() );
		$_opt = is_array( $_opt ) ? $_opt : array();
		if ( ! ( ( ! empty( $_opt['telegram_bot_token'] ) || defined( 'FATALGUARD_TELEGRAM_BOT_TOKEN' ) )
		         && ( ! empty( $_opt['telegram_chat_id'] ) || defined( 'FATALGUARD_TELEGRAM_CHAT_ID' ) ) ) ) {
			// Connected but not via Telegram — Pro channel.
			$channel_label = 'pro channel';
		}
	}

	echo '<div class="fg-readout" role="status" aria-label="' . esc_attr__( 'FatalGuard status', 'fatalguard' ) . '">';
	if ( $connected ) {
		echo '<span class="fg-sig fg-sig--on"><span class="dot"></span>' . esc_html__( 'Armed', 'fatalguard' ) . ' <em>' . esc_html( $channel_label ) . '</em></span>';
	} else {
		echo '<span class="fg-sig fg-sig--off"><span class="dot"></span>' . esc_html__( 'Standby', 'fatalguard' ) . ' <em>' . esc_html__( 'no destination', 'fatalguard' ) . '</em></span>';
	}

	// Capturing badge: show real state — distinct messages per failure mode.
	// B037/B046/B056/B096/B100/B123: use fatalguard_capture_active() as the gate.
	$cap_active  = fatalguard_capture_active();
	$cap_class   = $cap_active ? 'fg-sig--live' : 'fg-sig--err';
	if ( $cap_active ) {
		$cap_label = __( 'Capturing', 'fatalguard' );
	} else {
		// Determine the specific failure mode for the amber/red label.
		$disabled = ( function_exists( 'fatalguard_is_disabled' ) && fatalguard_is_disabled() )
			|| ( function_exists( 'gutenx_debug_logs_is_disabled' ) && gutenx_debug_logs_is_disabled() );
		$foreign  = ( function_exists( 'fatalguard_foreign_dropin_present' ) && fatalguard_foreign_dropin_present() )
			|| ( function_exists( 'gutenx_debug_logs_foreign_dropin_present' ) && gutenx_debug_logs_foreign_dropin_present() );
		if ( $disabled ) {
			/* translators: kill-switch was triggered by an auto-disarm or manual DISABLE file. */
			$cap_label = __( 'Disabled — Re-arm to restore capture', 'fatalguard' );
		} elseif ( $foreign ) {
			$cap_label = __( 'Not capturing — another handler is installed', 'fatalguard' );
		} else {
			$cap_label = __( 'Not capturing — drop-in not installed', 'fatalguard' );
		}
	}
	echo '<span class="fg-sig fg-sig ' . esc_attr( $cap_class ) . '"><span class="dot"></span>' . esc_html( $cap_label ) . '</span>';
	if ( $fatal_n > 0 ) {
		$last = $last_ts ? sprintf( /* translators: %s: time ago */ __( 'last %s ago', 'fatalguard' ), human_time_diff( $last_ts ) ) : '';
		printf(
			'<span class="fg-sig fg-sig--warn"><span class="dot"></span>%s <em>%s</em></span>',
			esc_html( sprintf( /* translators: %d: number of captured fatals */ _n( '%d captured', '%d captured', $fatal_n, 'fatalguard' ), $fatal_n ) ),
			esc_html( $last )
		);
	} else {
		echo '<span class="fg-sig fg-sig--ok"><span class="dot"></span>' . esc_html__( 'All clear', 'fatalguard' ) . '</span>';
	}
	echo '</div>';
	echo '</div>';

	$is_pro = function_exists( 'fatalguard_can_use_pro' ) && fatalguard_can_use_pro();

	// --- Tab bar ----------------------------------------------------------
	// Client-side tabs keep the ONE settings <form> intact (it still submits every
	// field); each panel is shown/hidden by data-tab. The AI/Upgrade panels live
	// outside the form (own forms / upsell). Active tab persists via the URL hash
	// + sessionStorage, so Save and the MCP redirect land on the right tab.
	// Free mirrors Pro: same tabs always, Pro sections shown as disabled previews
	// with an upgrade CTA. Plus a dedicated Upgrade tab.
	$tabs = array(
		'alerts'  => __( 'Alerts', 'fatalguard' ),
		'general' => __( 'General', 'fatalguard' ),
		'history' => __( 'Error history', 'fatalguard' ),
		'ai'      => __( 'AI (MCP)', 'fatalguard' ),
	);
	if ( ! $is_pro ) {
		$tabs['upgrade'] = __( 'Upgrade', 'fatalguard' );
	}
	$first_tab = array_key_first( $tabs );
	echo '<nav class="fg-tabs" role="tablist">';
	foreach ( $tabs as $id => $label ) {
		printf(
			'<button type="button" class="fg-tab" role="tab" id="fg-tab-%1$s" data-tab="%1$s" aria-controls="fg-panel-%1$s" aria-selected="%3$s" tabindex="%4$s">%2$s</button>',
			esc_attr( $id ),
			esc_html( $label ),
			esc_attr( $id === $first_tab ? 'true' : 'false' ),
			esc_attr( $id === $first_tab ? '0' : '-1' )
		);
	}
	echo '</nav>';

	echo '<form action="options.php" method="post">';
	settings_fields( 'fatalguard' );

	// --- Panel: Alerts ----------------------------------------------------
	echo '<div class="fg-panel" id="fg-panel-alerts" data-tab="alerts" role="tabpanel" aria-labelledby="fg-tab-alerts">';
	echo '<section class="fg-sec">';
	echo '<div class="fg-sec__head"><span class="fg-sec__label">' . esc_html__( 'Alerts', 'fatalguard' ) . '</span>';
	echo '<span class="fg-sec__hint">' . esc_html__( 'Free — one Telegram destination', 'fatalguard' ) . '</span></div>';

	echo '<div class="fg-card fg-card--hero">';
	echo '<div class="fg-card__top"><span class="fg-ico" aria-hidden="true">' . wp_kses( fatalguard_icon( 'telegram' ), fatalguard_icon_kses() ) . '</span>';
	echo '<div><h2>' . esc_html__( 'Telegram', 'fatalguard' ) . '</h2>';
	echo '<p class="fg-sub">' . esc_html__( 'Create a bot, press Start, and the chat is detected for you.', 'fatalguard' ) . '</p></div></div>';

	// The connect wizard (connect.js) mounts here.
	echo '<div id="fatalguard-connect-root"></div>';

	// Manual fallback, tucked away — devs who script it don't want a wizard.
	echo '<details class="fg-adv">';
	echo '<summary>' . esc_html__( 'Enter token and chat id manually', 'fatalguard' ) . '</summary>';

	echo '<div class="fg-field"><label for="fg-token">' . esc_html__( 'Bot token', 'fatalguard' ) . '</label>';
	$token_placeholder = $has_token ? __( 'saved — leave blank to keep', 'fatalguard' ) : '123456789:AA…';
	printf(
		'<input type="password" id="fg-token" name="%1$s[telegram_bot_token]" value="" autocomplete="new-password" placeholder="%2$s" />',
		esc_attr( $opt_name ),
		esc_attr( $token_placeholder )
	);
	echo '</div>';

	echo '<div class="fg-field"><label for="fg-chat">' . esc_html__( 'Chat id', 'fatalguard' ) . '</label>';
	printf(
		'<input type="text" id="fg-chat" name="%1$s[telegram_chat_id]" value="%2$s" autocomplete="off" placeholder="%3$s" />',
		esc_attr( $opt_name ),
		esc_attr( fatalguard_settings_val( 'telegram_chat_id' ) ),
		esc_attr( '-100123456789' )
	);
	echo '</div>';
	echo '</details>';

	echo '</div>'; // card
	echo '</section>';
	if ( $is_pro ) {
		fatalguard_settings_pro_channels( $opt_name );
	} else {
		fatalguard_pro_lock_open( 'channels' );
		fatalguard_settings_pro_channels( $opt_name );
		fatalguard_pro_lock_close();
	}
	echo '</div>'; // panel alerts

	// --- Panel: General (logging + pro stealth/digest) --------------------
	echo '<div class="fg-panel" id="fg-panel-general" data-tab="general" role="tabpanel" aria-labelledby="fg-tab-general" hidden>';
	echo '<section class="fg-sec">';
	echo '<div class="fg-sec__head"><span class="fg-sec__label">' . esc_html__( 'Logging', 'fatalguard' ) . '</span></div>';
	$file_logging = '' !== fatalguard_settings_val( 'file_logging' ) && fatalguard_settings_val( 'file_logging' );
	echo '<div class="fg-card"><label class="fg-check">';
	printf(
		'<input type="checkbox" name="%1$s[file_logging]" value="1" %2$s />',
		esc_attr( $opt_name ),
		checked( (bool) $file_logging, true, false )
	);
	echo '<span><span class="fg-check__t">' . esc_html__( 'Log PHP errors', 'fatalguard' ) . '</span>';
	echo '<span class="fg-check__d">' . esc_html__( 'Routes PHP warnings and notices into FatalGuard\'s protected log. Updates wp-config.php for you — turning it off restores your original settings.', 'fatalguard' ) . '</span></span>';
	echo '</label></div>';
	echo '<div class="fg-card"><label class="fg-check">';
	printf(
		'<input type="checkbox" name="%1$s[display_php_errors]" value="1" %2$s />',
		esc_attr( $opt_name ),
		checked( (bool) $display, true, false )
	);
	echo '<span><span class="fg-check__t">' . esc_html__( 'Show PHP errors on the front end', 'fatalguard' ) . '</span>';
	echo '<span class="fg-check__d">' . esc_html__( 'Sets WP_DEBUG_DISPLAY in wp-config.php. For local development only — never leave this on for a live site.', 'fatalguard' ) . '</span></span>';
	echo '</label></div>';
	fatalguard_wpconfig_notice_render();
	echo '</section>';
	if ( $is_pro ) {
		fatalguard_settings_pro_general( $opt_name );
	} else {
		fatalguard_pro_lock_open( 'general' );
		fatalguard_settings_pro_general( $opt_name );
		fatalguard_pro_lock_close();
	}
	echo '</div>'; // panel general

	// --- Panel: Error history (Pro; free shows a disabled preview) --------
	echo '<div class="fg-panel" id="fg-panel-history" data-tab="history" role="tabpanel" aria-labelledby="fg-tab-history" hidden>';
	if ( $is_pro ) {
		fatalguard_settings_pro_history( $opt_name );
	} else {
		echo '<section class="fg-sec"><div class="fg-sec__head"><span class="fg-sec__label">' . esc_html__( 'Error history', 'fatalguard' ) . '</span>';
		echo '<span class="fg-sec__hint">' . esc_html__( 'Pro — searchable database history', 'fatalguard' ) . '</span></div>';
		echo '<div class="fg-card"><p class="fg-sub">' . esc_html__( 'Pro keeps every fatal in a searchable database — grouped, with counts and first/last-seen — and adds a “History” tab to the log viewer. Free shows the live tail only.', 'fatalguard' ) . '</p>';
		printf(
			'<img class="fg-promo" src="%1$s" alt="%2$s" loading="lazy" />',
			esc_url( plugins_url( 'assets/promo-history.png', FATALGUARD_FILE ) ),
			esc_attr__( 'Searchable, grouped error history with counts and first/last-seen', 'fatalguard' )
		);
		echo '</div></section>';
		fatalguard_pro_lock_open( 'history' );
		fatalguard_settings_pro_history( $opt_name );
		fatalguard_pro_lock_close();
	}
	echo '</div>';

	submit_button( __( 'Save changes', 'fatalguard' ) );
	echo '</form>';

	// --- Panel: AI (MCP) — free + Pro: key + client snippets (mcp/settings-ui.php)
	echo '<div class="fg-panel" id="fg-panel-ai" data-tab="ai" role="tabpanel" aria-labelledby="fg-tab-ai" hidden>';
	if ( function_exists( 'fatalguard_mcp_render_section' ) ) {
		fatalguard_mcp_render_section( $opt_name );
	}
	echo '</div>';

	// --- Panel: Upgrade (free only) ---------------------------------------
	if ( ! $is_pro ) {
		echo '<div class="fg-panel" id="fg-panel-upgrade" data-tab="upgrade" role="tabpanel" aria-labelledby="fg-tab-upgrade" hidden>';
		fatalguard_settings_render_pro();
		echo '</div>';
	}

	echo '</div>'; // wrap
}

/*
 * The tab controller markup styling + behaviour (panels, keyboard nav, active-tab
 * memory, form-less Save hiding) and the "send test to all channels" handler now
 * ship as enqueued assets (assets/settings.css + assets/settings-ui.js), wired in
 * fatalguard_settings_assets() — no inline <style>/<script> is printed on the page.
 */

/** Pro: extra alert channels + routing (lives in the Alerts tab). */
function fatalguard_settings_pro_channels( string $opt_name ): void {
	echo '<section class="fg-sec">';
	echo '<div class="fg-sec__head"><span class="fg-sec__label">' . esc_html__( 'Alert channels', 'fatalguard' ) . '</span>';
	echo '<span class="fg-sec__hint">' . esc_html__( 'Pro — alongside Telegram', 'fatalguard' ) . '</span></div>';
	echo '<div class="fg-card">';
	fatalguard_settings_field( $opt_name, 'slack_webhook', __( 'Slack incoming webhook', 'fatalguard' ), 'url', 'https://hooks.slack.com/services/…', 'https://api.slack.com/messaging/webhooks', __( 'Create a Slack incoming webhook', 'fatalguard' ) );
	fatalguard_settings_field( $opt_name, 'discord_webhook', __( 'Discord webhook URL', 'fatalguard' ), 'url', 'https://discord.com/api/webhooks/…', 'https://support.discord.com/hc/en-us/articles/228383668-Intro-to-Webhooks', __( 'Create a Discord webhook', 'fatalguard' ) );
	fatalguard_settings_field( $opt_name, 'alert_email', __( 'Email address', 'fatalguard' ), 'email', 'ops@example.com' );
	fatalguard_settings_field( $opt_name, 'webhook', __( 'Custom webhook (JSON POST)', 'fatalguard' ), 'url', 'https://example.com/hook', 'https://fatalguard.com/docs/pro-alert-channels', __( 'Custom webhook payload & setup', 'fatalguard' ) );
	fatalguard_channels_test_button();
	echo '</div></section>';

	echo '<section class="fg-sec">';
	echo '<div class="fg-sec__head"><span class="fg-sec__label">' . esc_html__( 'Alert routing', 'fatalguard' ) . '</span></div>';
	echo '<div class="fg-card"><div class="fg-field">';
	echo '<label for="fg-targets">' . esc_html__( 'Extra Telegram targets', 'fatalguard' ) . '</label>';
	printf(
		'<textarea id="fg-targets" name="%1$s[telegram_targets]" rows="3" placeholder="%2$s">%3$s</textarea>',
		esc_attr( $opt_name ),
		esc_attr( '-100123 | acme | optional_bot_token' ),
		esc_textarea( fatalguard_settings_val( 'telegram_targets' ) )
	);
	echo '<p class="fg-check__d">' . esc_html__( 'One per line: chat_id | plugin-slug | optional bot token. Fatals from that plugin also go to this chat.', 'fatalguard' ) . '</p>';
	echo '</div></div></section>';
}

/**
 * "Send test to all channels" button. Fires a live test to every SAVED channel
 * (Telegram + Slack/Discord/webhook/email) via the REST route and lists a
 * per-channel result. Tests the saved config, so it reflects what a real fatal
 * would use — Save first if you just edited a field.
 */
function fatalguard_channels_test_button(): void {
	$url   = esc_url_raw( rest_url( 'fatalguard/v1/debug-logs/test-alerts' ) );
	$nonce = wp_create_nonce( 'wp_rest' );
	echo '<div class="fg-test-all">';
	$dismiss_url   = esc_url_raw( admin_url( 'admin-ajax.php?action=fatalguard_dismiss_failures' ) );
	$dismiss_nonce = wp_create_nonce( 'fatalguard_dismiss' );
	printf(
		'<button type="button" class="button button-secondary fg-test-btn" data-fg-test-url="%1$s" data-fg-test-nonce="%2$s" data-fg-dismiss-url="%4$s" data-fg-dismiss-nonce="%5$s">%3$s</button>',
		esc_attr( $url ),
		esc_attr( $nonce ),
		esc_html__( 'Send test to all channels', 'fatalguard' ),
		esc_attr( $dismiss_url ),
		esc_attr( $dismiss_nonce )
	);
	echo '<span class="fg-check__d fg-test-note">' . esc_html__( 'Sends a test to every saved channel. Save first if you just changed one.', 'fatalguard' ) . '</span>';
	echo '<ul class="fg-test-results" hidden></ul>';
	echo '</div>';
}


/** Pro: stealth mode + weekly digest (lives in the General tab). */
function fatalguard_settings_pro_general( string $opt_name ): void {
	echo '<section class="fg-sec">';
	echo '<div class="fg-sec__head"><span class="fg-sec__label">' . esc_html__( 'Stealth mode', 'fatalguard' ) . '</span></div>';
	echo '<div class="fg-card"><div class="fg-field">';
	echo '<label for="fg-stealth">' . esc_html__( 'Show FatalGuard only to these admins', 'fatalguard' ) . '</label>';
	printf(
		'<textarea id="fg-stealth" name="%1$s[stealth_allowlist]" rows="2" placeholder="%2$s">%3$s</textarea>',
		esc_attr( $opt_name ),
		esc_attr( 'you@example.com, dev@agency.com' ),
		esc_textarea( fatalguard_settings_val( 'stealth_allowlist' ) )
	);
	echo '<p class="fg-check__d">' . esc_html__( 'Admin emails, comma- or line-separated. Everyone else won\'t see the plugin. Blank = show to all.', 'fatalguard' ) . '</p>';
	echo '</div></div></section>';

	echo '<section class="fg-sec">';
	echo '<div class="fg-sec__head"><span class="fg-sec__label">' . esc_html__( 'Weekly digest', 'fatalguard' ) . '</span></div>';
	echo '<div class="fg-card">';
	fatalguard_settings_field( $opt_name, 'digest_recipient', __( 'Send a weekly summary to', 'fatalguard' ), 'email', 'you@example.com' );
	echo '</div></section>';
}

/** Pro: error-history retention (lives in the History tab). */
function fatalguard_settings_pro_history( string $opt_name ): void {
	$retention = fatalguard_settings_val( 'history_retention_days' );
	$retention = ( '' === $retention ) ? '90' : $retention;
	echo '<section class="fg-sec">';
	echo '<div class="fg-sec__head"><span class="fg-sec__label">' . esc_html__( 'Error history', 'fatalguard' ) . '</span>';
	echo '<span class="fg-sec__hint">' . esc_html__( 'Pro — searchable database history', 'fatalguard' ) . '</span></div>';
	echo '<div class="fg-card"><div class="fg-field">';
	echo '<label for="fg-retention">' . esc_html__( 'Keep history for (days)', 'fatalguard' ) . '</label>';
	printf(
		'<input type="number" id="fg-retention" name="%1$s[history_retention_days]" value="%2$s" min="0" max="3650" step="1" />',
		esc_attr( $opt_name ),
		esc_attr( $retention )
	);
	echo '<p class="fg-check__d">' . esc_html__( 'Old entries are pruned daily. Use 0 to keep everything.', 'fatalguard' ) . '</p>';
	echo '</div></div></section>';
}

/** One labelled text/url/email input, value pulled from saved settings. */
function fatalguard_settings_field( string $opt_name, string $key, string $label, string $type, string $placeholder, string $help_url = '', string $help_text = '' ): void {
	echo '<div class="fg-field">';
	printf( '<label for="fg-%1$s">%2$s</label>', esc_attr( $key ), esc_html( $label ) );
	printf(
		'<input type="%1$s" id="fg-%2$s" name="%3$s[%2$s]" value="%4$s" placeholder="%5$s" autocomplete="off" />',
		esc_attr( $type ),
		esc_attr( $key ),
		esc_attr( $opt_name ),
		esc_attr( fatalguard_settings_val( $key ) ),
		esc_attr( $placeholder )
	);
	if ( '' !== $help_url ) {
		printf(
			'<p class="fg-field__help"><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s ↗</a></p>',
			esc_url( $help_url ),
			esc_html( '' !== $help_text ? $help_text : __( 'How to set this up', 'fatalguard' ) )
		);
	}
	echo '</div>';
}

/** Pro upgrade URL with a campaign source. */
function fatalguard_pro_upgrade_url( string $where ): string {
	return 'https://fatalguard.com/pro/?utm_source=plugin&utm_medium=settings&utm_campaign=' . rawurlencode( $where );
}

/**
 * Wrap a Pro settings block for FREE installs: a clear upgrade CTA above, then a
 * DISABLED <fieldset> so the real fields show as a greyed-out live preview and are
 * never submitted. Pro installs skip this and get the interactive fields. Call
 * fatalguard_pro_lock_close() after the block.
 */
function fatalguard_pro_lock_open( string $where = 'settings' ): void {
	echo '<div class="fg-lock">';
	echo '<div class="fg-lock__cta"><span class="fg-lock__badge">🔒 ' . esc_html__( 'Pro', 'fatalguard' ) . '</span>';
	echo '<span class="fg-lock__txt">' . esc_html__( 'Preview — available in FatalGuard Pro.', 'fatalguard' ) . '</span>';
	echo '<a class="fg-lock__btn" href="' . esc_url( fatalguard_pro_upgrade_url( $where ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Upgrade to Pro', 'fatalguard' ) . ' →</a></div>';
	echo '<fieldset class="fg-lock__fields" disabled>';
}
function fatalguard_pro_lock_close(): void {
	echo '</fieldset></div>';
}

/** Locked upsell cards. Disabled, clearly labelled Pro — an invitation, not a dead field. */
function fatalguard_settings_render_pro(): void {
	$features = array(
		array( 'history', __( 'Error history', 'fatalguard' ), __( 'Searchable, grouped history of every fatal. AI also reads your full error history.', 'fatalguard' ) ),
		array( 'slack', __( 'Slack', 'fatalguard' ), __( 'Post fatals to a channel via an incoming webhook.', 'fatalguard' ) ),
		array( 'discord', __( 'Discord', 'fatalguard' ), __( 'Send alerts to a Discord channel.', 'fatalguard' ) ),
		array( 'email', __( 'Email', 'fatalguard' ), __( 'Get a mail whenever a fatal is captured.', 'fatalguard' ) ),
		array( 'webhook', __( 'Custom webhook', 'fatalguard' ), __( 'JSON POST to any URL — Zapier, Make, your own bus.', 'fatalguard' ) ),
		array( 'routing', __( 'Alert routing', 'fatalguard' ), __( 'Send a plugin\'s fatals to their own Telegram target.', 'fatalguard' ) ),
		array( 'stealth', __( 'Stealth mode', 'fatalguard' ), __( 'Hide FatalGuard from every other administrator.', 'fatalguard' ) ),
		array( 'digest', __( 'Weekly digest', 'fatalguard' ), __( 'An emailed summary of the week\'s errors.', 'fatalguard' ) ),
	);

	echo '<section class="fg-sec">';
	echo '<div class="fg-pro__head"><span class="fg-sec__label">' . esc_html__( 'More channels', 'fatalguard' );
	echo ' <span class="fg-badge">PRO</span></span>';
	echo '<a class="fg-upgrade" href="https://fatalguard.com/pro/" target="_blank" rel="noopener">' . esc_html__( 'Upgrade to Pro', 'fatalguard' ) . ' →</a>';
	echo '</div>';

	echo '<div class="fg-pro-grid">';
	foreach ( $features as $f ) {
		echo '<div class="fg-lock">';
		echo '<span class="fg-lock__pad" aria-hidden="true">🔒</span>';
		echo '<div class="fg-lock__top"><span class="fg-ico" aria-hidden="true">' . wp_kses( fatalguard_icon( $f[0] ), fatalguard_icon_kses() ) . '</span>';
		echo '<h3>' . esc_html( $f[1] ) . '</h3></div>';
		echo '<p>' . esc_html( $f[2] ) . '</p>';
		echo '</div>';
	}
	echo '</div>';
	echo '</section>';
}

/** Plugins-list row: quick "Alerts" link to the settings screen + a docs link. */
// B168/B176: use plugin_basename(FATALGUARD_FILE) so the hook fires on premium
// (fatalguard-premium/) and free-zip (fatalguard-free/) installs, not just fatalguard/.
add_filter( 'plugin_action_links_' . plugin_basename( FATALGUARD_FILE ), 'fatalguard_action_links' );
function fatalguard_action_links( $links ) {
	$links = (array) $links;
	$url   = fatalguard_settings_page_url();
	$link  = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Alerts', 'fatalguard' ) . '</a>';
	array_unshift( $links, $link );
	return $links;
}

add_filter( 'plugin_row_meta', 'fatalguard_row_meta', 10, 2 );
function fatalguard_row_meta( $links, $file ) {
	if ( $file === plugin_basename( FATALGUARD_FILE ) ) {
		$links[] = '<a href="https://fatalguard.com/docs/" target="_blank" rel="noopener">' . esc_html__( 'Docs', 'fatalguard' ) . '</a>';
	}
	return $links;
}
