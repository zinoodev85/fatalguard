<?php
/**
 * Telegram connect wizard — the easy path to wiring alerts, standalone-only.
 *
 * Users create a bot in @BotFather (unavoidable — Telegram has no API to create a
 * bot), paste the token, press Start in the bot, and we auto-detect the chat id
 * via getUpdates (the one flow that PROVES the bot can message them — a bot cannot
 * DM a user who never pressed Start). No hosted infrastructure: creds live on-site
 * and the module still sends fatal alerts directly.
 *
 * This overlay file adds:
 *   - REST routes fatalguard/v1/telegram/{resolve,detect,save,disconnect} that call
 *     the USER's own bot token (never a shared one).
 *   - The connect.js asset, mounted on the settings page AND as an "Alerts" tab in
 *     the log popup (via the module's registerTab seam).
 *
 * @package FatalGuard
 */

defined( 'ABSPATH' ) || exit;

const FATALGUARD_CONNECT_API   = 'https://api.telegram.org/bot';
const FATALGUARD_CONNECT_TOKEN = '/^\d{5,}:[A-Za-z0-9_-]{30,}$/'; // BotFather token shape

/**
 * Permission: manage_options AND an explicit wp_rest nonce. Mirrors the module's
 * own gate (kept local so this overlay doesn't depend on module internals).
 */
function fatalguard_connect_permission( WP_REST_Request $request ): bool {
	if ( ! current_user_can( 'manage_options' ) ) {
		return false;
	}
	$nonce = $request->get_header( 'x_wp_nonce' );
	if ( empty( $nonce ) ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
	}
	return (bool) wp_verify_nonce( (string) $nonce, 'wp_rest' );
}

/** True when a string looks like a real BotFather token. */
function fatalguard_connect_valid_token( string $token ): bool {
	return 1 === preg_match( FATALGUARD_CONNECT_TOKEN, $token );
}

/** Human label for a Telegram chat array: its title, or a person's name, or @username. */
function fatalguard_connect_chat_title( array $c ): string {
	if ( ! empty( $c['title'] ) ) {
		return (string) $c['title'];
	}
	$name = trim( ( $c['first_name'] ?? '' ) . ' ' . ( $c['last_name'] ?? '' ) );
	if ( '' === $name && ! empty( $c['username'] ) ) {
		$name = '@' . $c['username'];
	}
	return $name;
}

/** GET a Telegram Bot API method, returning the decoded body or a WP_Error. */
function fatalguard_connect_tg_call( string $token, string $method, array $query = array() ) {
	$url = FATALGUARD_CONNECT_API . $token . '/' . $method;
	if ( $query ) {
		$url = add_query_arg( $query, $url ); // add_query_arg url-encodes values
	}
	$resp = wp_remote_get( $url, array( 'timeout' => 8, 'redirection' => 0 ) );
	if ( is_wp_error( $resp ) ) {
		return $resp;
	}
	$code = (int) wp_remote_retrieve_response_code( $resp );
	$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );

	if ( 409 === $code ) {
		return new WP_Error( 'fatalguard_tg_webhook', 'This bot has a webhook set — remove it in BotFather or use a fresh bot.', array( 'status' => 200 ) );
	}
	if ( ! is_array( $body ) ) {
		return new WP_Error( 'fatalguard_tg_bad_response', 'Unexpected response from Telegram.', array( 'status' => 200 ) );
	}
	if ( empty( $body['ok'] ) ) {
		$desc = ! empty( $body['description'] ) ? (string) $body['description'] : ( 'HTTP ' . $code );
		return new WP_Error( 'fatalguard_tg_error', $desc, array( 'status' => 200 ) );
	}
	return $body;
}

/** Register the connect routes. */
function fatalguard_connect_register_rest(): void {
	$ns   = 'fatalguard/v1';
	$perm = 'fatalguard_connect_permission';
	$tok  = array( 'token' => array( 'type' => 'string', 'required' => true ) );

	register_rest_route( $ns, '/telegram/resolve', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'args'                => $tok,
		'callback'            => 'fatalguard_connect_resolve',
	) );

	register_rest_route( $ns, '/telegram/detect', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'args'                => $tok,
		'callback'            => 'fatalguard_connect_detect',
	) );

	// Resolve the SAVED chat's display name (getChat on stored creds — token stays server-side).
	register_rest_route( $ns, '/telegram/chat', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => 'fatalguard_connect_chat',
	) );

	register_rest_route( $ns, '/telegram/save', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'args'                => array(
			'token'   => array( 'type' => 'string', 'required' => true ),
			'chat_id' => array( 'type' => 'string', 'required' => true ),
		),
		'callback'            => 'fatalguard_connect_save',
	) );

	register_rest_route( $ns, '/telegram/disconnect', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => 'fatalguard_connect_disconnect',
	) );
}
add_action( 'rest_api_init', 'fatalguard_connect_register_rest' );

/** getMe → validate the token, return the bot's @username. */
function fatalguard_connect_resolve( WP_REST_Request $request ) {
	$token = trim( (string) $request->get_param( 'token' ) );
	if ( ! fatalguard_connect_valid_token( $token ) ) {
		return rest_ensure_response( array( 'ok' => false, 'message' => __( 'That does not look like a bot token. Copy the whole line BotFather gave you.', 'fatalguard' ) ) );
	}
	$body = fatalguard_connect_tg_call( $token, 'getMe' );
	if ( is_wp_error( $body ) ) {
		return rest_ensure_response( array( 'ok' => false, 'message' => $body->get_error_message() ) );
	}
	$r = isset( $body['result'] ) && is_array( $body['result'] ) ? $body['result'] : array();
	return rest_ensure_response( array(
		'ok'       => true,
		'username' => isset( $r['username'] ) ? (string) $r['username'] : '',
		'name'     => isset( $r['first_name'] ) ? (string) $r['first_name'] : '',
	) );
}

/**
 * getUpdates → the distinct chats that have messaged the bot. Short timeout=0
 * (no long-poll — never tie a PHP worker for 30s); no offset (read-only, so
 * repeated polls keep seeing the Start until it ages out ~24h).
 */
function fatalguard_connect_detect( WP_REST_Request $request ) {
	$token = trim( (string) $request->get_param( 'token' ) );
	if ( ! fatalguard_connect_valid_token( $token ) ) {
		return rest_ensure_response( array( 'ok' => false, 'message' => __( 'Invalid token.', 'fatalguard' ) ) );
	}
	$body = fatalguard_connect_tg_call( $token, 'getUpdates', array( 'timeout' => '0', 'limit' => '20' ) );
	if ( is_wp_error( $body ) ) {
		return rest_ensure_response( array( 'ok' => false, 'message' => $body->get_error_message() ) );
	}

	$chats   = array();
	$updates = isset( $body['result'] ) && is_array( $body['result'] ) ? $body['result'] : array();
	foreach ( $updates as $u ) {
		foreach ( array( 'message', 'my_chat_member', 'channel_post' ) as $k ) {
			if ( ! empty( $u[ $k ]['chat'] ) && is_array( $u[ $k ]['chat'] ) ) {
				$c  = $u[ $k ]['chat'];
				$id = isset( $c['id'] ) ? (string) $c['id'] : '';
				if ( '' === $id || isset( $chats[ $id ] ) ) {
					continue;
				}
				$chats[ $id ] = array(
					'id'    => $id,
					'title' => fatalguard_connect_chat_title( $c ),
					'type'  => isset( $c['type'] ) ? (string) $c['type'] : '',
				);
			}
		}
	}

	return rest_ensure_response( array( 'ok' => true, 'chats' => array_values( $chats ) ) );
}

/** Persist token + chat id into fatalguard_settings (leaving other keys intact). */
function fatalguard_connect_save( WP_REST_Request $request ) {
	$token   = trim( (string) $request->get_param( 'token' ) );
	$chat_id = trim( (string) $request->get_param( 'chat_id' ) );
	if ( ! fatalguard_connect_valid_token( $token ) ) {
		return rest_ensure_response( array( 'ok' => false, 'message' => __( 'Invalid token.', 'fatalguard' ) ) );
	}
	if ( ! preg_match( '/^-?\d{3,}$/', $chat_id ) ) {
		return rest_ensure_response( array( 'ok' => false, 'message' => __( 'Invalid chat id.', 'fatalguard' ) ) );
	}
	// Resolve the chat's display name now (getChat) so the connected card can show it.
	$title    = '';
	$username = '';
	$meta     = fatalguard_connect_tg_call( $token, 'getChat', array( 'chat_id' => $chat_id ) );
	if ( ! is_wp_error( $meta ) && ! empty( $meta['result'] ) && is_array( $meta['result'] ) ) {
		$title    = fatalguard_connect_chat_title( $meta['result'] );
		$username = (string) ( $meta['result']['username'] ?? '' );
	}

	$opt                          = fatalguard_connect_settings();
	$opt['telegram_bot_token']    = $token;
	$opt['telegram_chat_id']      = $chat_id;
	$opt['telegram_chat_title']   = $title;
	$opt['telegram_chat_username'] = $username;
	update_option( FATALGUARD_SETTINGS_OPTION, $opt );
	return rest_ensure_response( array( 'ok' => true, 'chat_id' => $chat_id, 'title' => $title, 'username' => $username ) );
}

/** getChat on the SAVED creds → the chat's display name (token never leaves the server). */
function fatalguard_connect_chat( WP_REST_Request $request ) {
	$opt   = fatalguard_connect_settings();
	$token = trim( (string) ( $opt['telegram_bot_token'] ?? '' ) );
	$chat  = trim( (string) ( $opt['telegram_chat_id'] ?? '' ) );
	if ( '' === $token || '' === $chat ) {
		return rest_ensure_response( array( 'ok' => false ) );
	}
	$body = fatalguard_connect_tg_call( $token, 'getChat', array( 'chat_id' => $chat ) );
	if ( is_wp_error( $body ) ) {
		return rest_ensure_response( array( 'ok' => false, 'message' => $body->get_error_message() ) );
	}
	$c = isset( $body['result'] ) && is_array( $body['result'] ) ? $body['result'] : array();
	return rest_ensure_response( array(
		'ok'       => true,
		'chat_id'  => $chat,
		'title'    => fatalguard_connect_chat_title( $c ),
		'username' => isset( $c['username'] ) ? (string) $c['username'] : '',
		'type'     => isset( $c['type'] ) ? (string) $c['type'] : '',
	) );
}

/** Clear the Telegram creds → next load the bridge stops defining the constant. */
function fatalguard_connect_disconnect( WP_REST_Request $request ) {
	$opt                          = fatalguard_connect_settings();
	$opt['telegram_bot_token']    = '';
	$opt['telegram_chat_id']      = '';
	$opt['telegram_chat_title']   = '';
	$opt['telegram_chat_username'] = '';
	update_option( FATALGUARD_SETTINGS_OPTION, $opt );
	return rest_ensure_response( array( 'ok' => true ) );
}

/** The saved settings array (always an array). */
function fatalguard_connect_settings(): array {
	$o = get_option( FATALGUARD_SETTINGS_OPTION, array() );
	return is_array( $o ) ? $o : array();
}

/**
 * Compact status of every alert channel for the log-popup "Alerts" tab: Telegram
 * (free) plus the Pro channels when Pro is active. Each entry carries whether it's
 * configured and whether it's currently failing (from the recorded delivery
 * failures), so the log viewer shows at a glance where alerts go and what's broken.
 *
 * @return array<int,array<string,mixed>>
 */
function fatalguard_connect_channels_summary(): array {
	$opt = fatalguard_connect_settings();

	// Which channels have a recorded delivery failure (newest-first option).
	$failures = get_option( 'fatalguard_delivery_failures', array() );
	$failures = is_array( $failures ) ? $failures : array();
	$failing  = array();
	foreach ( $failures as $entry ) {
		if ( is_array( $entry ) && ! empty( $entry['channel'] ) ) {
			$failing[ strtolower( (string) $entry['channel'] ) ] = true;
		}
	}
	$is_failing = static function ( string $label ) use ( $failing ): bool {
		return isset( $failing[ strtolower( $label ) ] );
	};

	// Telegram (free): saved creds OR wp-config constants.
	$tg_configured = ( ! empty( $opt['telegram_bot_token'] ) && ! empty( $opt['telegram_chat_id'] ) )
		|| ( defined( 'FATALGUARD_TELEGRAM_BOT_TOKEN' ) && '' !== FATALGUARD_TELEGRAM_BOT_TOKEN
			&& defined( 'FATALGUARD_TELEGRAM_CHAT_ID' ) && '' !== FATALGUARD_TELEGRAM_CHAT_ID );
	$channels = array(
		array( 'key' => 'telegram', 'label' => __( 'Telegram', 'fatalguard' ), 'configured' => $tg_configured, 'failing' => $is_failing( 'Telegram' ), 'pro' => false ),
	);

	// Pro channels — ALWAYS listed, mirroring the settings page ("Free mirrors Pro":
	// same channels always, marked Pro on the free build). On free they render as a
	// locked "Pro" preview; on Pro they show real configured/failing status.
	$is_pro = function_exists( 'fatalguard_can_use_pro' )
		? fatalguard_can_use_pro()
		: ( function_exists( 'fatalguard_is_pro' ) && fatalguard_is_pro() );
	$pro_defs = array(
		array( 'key' => 'slack',   'label' => __( 'Slack', 'fatalguard' ),   'opt' => 'slack_webhook' ),
		array( 'key' => 'discord', 'label' => __( 'Discord', 'fatalguard' ), 'opt' => 'discord_webhook' ),
		array( 'key' => 'email',   'label' => __( 'Email', 'fatalguard' ),   'opt' => 'alert_email' ),
		array( 'key' => 'webhook', 'label' => __( 'Webhook', 'fatalguard' ), 'opt' => 'webhook' ),
	);
	foreach ( $pro_defs as $d ) {
		$configured = ( 'email' === $d['key'] )
			? ( ! empty( $opt['alert_email'] ) && is_email( (string) $opt['alert_email'] ) )
			: ! empty( $opt[ $d['opt'] ] );
		$channels[] = array(
			'key'        => $d['key'],
			'label'      => $d['label'],
			'configured' => $is_pro && $configured,
			'failing'    => $is_pro && $is_failing( $d['label'] ),
			'pro'        => true,
			'locked'     => ! $is_pro,
		);
	}
	return $channels;
}

/**
 * Enqueue connect.js/css wherever the log popup can show (front + admin bar) so
 * the "Alerts" tab is available, and on the settings page for the inline mount.
 */
function fatalguard_connect_enqueue( $hook = '' ): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// Load wherever the popup can show (admin bar) OR on our own settings screen
	// (so the inline wizard works even if the admin bar is turned off).
	$on_settings = ( 'toplevel_page_fatalguard' === $hook );
	if ( ! $on_settings && ! is_admin_bar_showing() ) {
		return;
	}
	$base = plugins_url( 'assets/', FATALGUARD_FILE );
	wp_enqueue_style( 'fatalguard-connect', $base . 'connect.css', array(), FATALGUARD_VERSION );
	wp_enqueue_script( 'fatalguard-connect', $base . 'connect.js', array(), FATALGUARD_VERSION, true );
	wp_set_script_translations( 'fatalguard-connect', 'fatalguard' );

	$opt   = fatalguard_connect_settings();
	$token = (string) ( $opt['telegram_bot_token'] ?? '' );
	$chat  = (string) ( $opt['telegram_chat_id'] ?? '' );
	wp_localize_script( 'fatalguard-connect', 'fatalguardConnectData', array(
		'rest'         => esc_url_raw( rest_url( 'fatalguard/v1/telegram/' ) ),
		'test'         => esc_url_raw( rest_url( 'fatalguard/v1/debug-logs/test-telegram' ) ),
		'nonce'        => wp_create_nonce( 'wp_rest' ),
		'configured'   => ( '' !== $token && '' !== $chat ),
		'chatId'       => $chat,
		'chatTitle'    => (string) ( $opt['telegram_chat_title'] ?? '' ),
		'chatUsername' => (string) ( $opt['telegram_chat_username'] ?? '' ),
		'botFather'    => 'https://t.me/BotFather',
		'channels'     => fatalguard_connect_channels_summary(),
		'i18n'         => array(
			'channelsHeading'    => __( 'Alert channels', 'fatalguard' ),
			'chStatusActive'     => __( 'active', 'fatalguard' ),
			'chStatusFailing'    => __( 'failing', 'fatalguard' ),
			'chStatusOff'        => __( 'not set up', 'fatalguard' ),
			'chStatusPro'        => __( 'Pro', 'fatalguard' ),
			'telegramConnected'  => __( 'Telegram connected ✓', 'fatalguard' ),
			'fatalAlertsGoTo'    => __( 'Fatal-error alerts go to ', 'fatalguard' ),
			'sendTestMessage'    => __( 'Send test message', 'fatalguard' ),
			'disconnect'         => __( 'Disconnect', 'fatalguard' ),
			'connectTelegramAlerts' => __( 'Connect Telegram alerts', 'fatalguard' ),
			'step1CreateBot'     => __( '1. Create your bot in Telegram (~30s)', 'fatalguard' ),
			'step1OpenBotFather' => __( 'Open @BotFather in Telegram.', 'fatalguard' ),
			'step1SendNewbot'    => __( 'Send /newbot.', 'fatalguard' ),
			'step1GiveName'      => __( 'Give it a name (e.g. "Acme Site Alerts").', 'fatalguard' ),
			'step1GiveUsername'  => __( 'Give it a username ending in "bot".', 'fatalguard' ),
			'step1CopyToken'     => __( 'Copy the API token BotFather sends back.', 'fatalguard' ),
			'openBotFather'      => __( 'Open @BotFather', 'fatalguard' ),
			'copyNewbot'         => __( 'Copy /newbot', 'fatalguard' ),
			'step2PasteToken'    => __( '2. Paste the bot token', 'fatalguard' ),
			'step3OpenBot'       => __( '3. Open your bot and press Start', 'fatalguard' ),
			'openAt'             => __( 'Open @', 'fatalguard' ),
			'copyLink'           => __( 'Copy link', 'fatalguard' ),
			'waitingForStart'    => __( 'Waiting for you to press Start…', 'fatalguard' ),
			'noChatDetected'     => __( 'No chat detected yet. Open the bot, press Start, then Retry.', 'fatalguard' ),
			'retry'              => __( 'Retry', 'fatalguard' ),
			'whichChat'          => __( 'Which chat should alerts go to?', 'fatalguard' ),
			'pasteBotTokenFirst' => __( 'Paste the bot token first.', 'fatalguard' ),
			'couldNotVerifyToken' => __( 'Could not verify the token.', 'fatalguard' ),
			'saveFailed'         => __( 'Save failed.', 'fatalguard' ),
			'networkError'       => __( 'Network error.', 'fatalguard' ),
			'sending'            => __( 'Sending…', 'fatalguard' ),
			'disconnectConfirm'  => __( 'Disconnect Telegram? Fatal alerts will stop until you reconnect.', 'fatalguard' ),
			'reCheck'            => __( 'Re-check', 'fatalguard' ),
			'connect'            => __( 'Connect', 'fatalguard' ),
		),
	) );
}
add_action( 'admin_enqueue_scripts', 'fatalguard_connect_enqueue' );
add_action( 'wp_enqueue_scripts', 'fatalguard_connect_enqueue' );
