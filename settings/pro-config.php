<?php
/**
 * FatalGuard Pro config bridge — feeds saved `fatalguard_settings` into the
 * (renamed) filter tags the Pro modules read: alert channels, extra Telegram
 * targets, stealth allowlist, weekly-digest recipient, and the plugin basename
 * stealth hides. Required from settings-page.php so the wiring is always present,
 * even when the settings screen isn't rendered.
 *
 * On a FREE install the `pro/` dir is stripped, so these filters have no consumer;
 * on Pro they configure the modules. All callbacks read the option lazily (when
 * the filter fires), so they are safe to register at load.
 *
 * @package FatalGuard
 */

defined( 'ABSPATH' ) || exit;

/** The saved settings array. */
function fatalguard_settings_get(): array {
	$o = get_option( FATALGUARD_SETTINGS_OPTION, array() );
	return is_array( $o ) ? $o : array();
}

add_filter( 'fatalguard_channels', 'fatalguard_cfg_channels' );
function fatalguard_cfg_channels( $unused ): array {
	$o = fatalguard_settings_get();
	return array(
		'slack_webhook'   => (string) ( $o['slack_webhook'] ?? '' ),
		'discord_webhook' => (string) ( $o['discord_webhook'] ?? '' ),
		'webhook'         => (string) ( $o['webhook'] ?? '' ),
		'email'           => (string) ( $o['alert_email'] ?? '' ),
	);
}

add_filter( 'fatalguard_telegram_targets', 'fatalguard_cfg_targets' );
function fatalguard_cfg_targets( $unused ): array {
	$raw = (string) ( fatalguard_settings_get()['telegram_targets'] ?? '' );
	$out = array();
	foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
		$line = trim( (string) $line );
		if ( '' === $line ) {
			continue;
		}
		// "chat_id" | "chat_id|origin-route" | "chat_id|route|bot_token"
		$p     = array_map( 'trim', explode( '|', $line ) );
		$out[] = array(
			'chat_id'   => $p[0],
			'route'     => $p[1] ?? '',
			'bot_token' => $p[2] ?? '',
		);
	}
	return $out;
}

add_filter( 'fatalguard_stealth_allowlist', 'fatalguard_cfg_stealth' );
function fatalguard_cfg_stealth( $unused ): array {
	$raw   = (string) ( fatalguard_settings_get()['stealth_allowlist'] ?? '' );
	$parts = preg_split( '/[\r\n,]+/', $raw );
	return array_values( array_filter( array_map( 'trim', is_array( $parts ) ? $parts : array() ) ) );
}

add_filter( 'fatalguard_digest_recipient', 'fatalguard_cfg_digest' );
function fatalguard_cfg_digest( $unused ): string {
	return (string) ( fatalguard_settings_get()['digest_recipient'] ?? '' );
}

add_filter( 'fatalguard_plugin_basename', 'fatalguard_cfg_basename' );
function fatalguard_cfg_basename( $unused ): string {
	// Resolve dynamically from the running file — on a Freemius PREMIUM install the
	// plugin lives in fatalguard-premium/, not fatalguard/, so a hardcoded basename
	// would leave the row visible (stealth would silently fail to hide it).
	if ( defined( 'FATALGUARD_FILE' ) && function_exists( 'plugin_basename' ) ) {
		return plugin_basename( FATALGUARD_FILE );
	}
	return 'fatalguard/fatalguard.php';
}
