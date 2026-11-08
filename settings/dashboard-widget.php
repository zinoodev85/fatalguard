<?php
/**
 * FatalGuard dashboard widget — the at-a-glance card on wp-admin's home screen:
 * the most recent fatal (or an all-clear), whether alerts are wired, and a quiet
 * Pro nudge. Read-only; every action links back to the settings screen.
 *
 * @package FatalGuard
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_dashboard_setup', 'fatalguard_dashboard_setup' );
function fatalguard_dashboard_setup(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// B038/B076: Stealth mode must hide the widget from non-allowlisted admins.
	if ( function_exists( 'fatalguard_stealth_hidden' ) && fatalguard_stealth_hidden() ) {
		return;
	}
	wp_add_dashboard_widget(
		'fatalguard_status',
		__( 'FatalGuard — error watch', 'fatalguard' ),
		'fatalguard_dashboard_render'
	);
}

/** Enqueue the widget stylesheet on the dashboard screen only (for users who see the widget). */
add_action( 'admin_enqueue_scripts', 'fatalguard_dashboard_assets' );
function fatalguard_dashboard_assets( $hook ): void {
	if ( 'index.php' !== $hook || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( function_exists( 'fatalguard_stealth_hidden' ) && fatalguard_stealth_hidden() ) {
		return;
	}
	wp_enqueue_style(
		'fatalguard-dashboard-widget',
		plugins_url( 'assets/dashboard-widget.css', FATALGUARD_FILE ),
		array(),
		FATALGUARD_VERSION
	);
}

/** Render the widget. Inline styles keep it self-contained (no extra enqueue on the home screen). */
function fatalguard_dashboard_render(): void {
	$latest    = fatalguard_dashboard_latest();
	// B100/B130: use fatalguard_any_channel_active() so Pro-only users (Slack/email) are not told "alerts are off".
	// B074: fall back to fatalguard_telegram_configured() (honours wp-config constants) rather than false.
	if ( function_exists( 'fatalguard_any_channel_active' ) ) {
		$connected = fatalguard_any_channel_active();
	} elseif ( function_exists( 'fatalguard_telegram_configured' ) ) {
		$connected = fatalguard_telegram_configured();
	} else {
		$connected = false;
	}
	$opt       = get_option( FATALGUARD_SETTINGS_OPTION, array() );
	$opt       = is_array( $opt ) ? $opt : array();
	$settings  = function_exists( 'fatalguard_settings_page_url' ) ? fatalguard_settings_page_url() : admin_url( 'admin.php?page=fatalguard' );

	echo '<div class="fg-dash">';

	// Alert status line — health first: a recorded delivery failure (a failed send,
	// or the weekly silent health check) means a configured channel is broken, so a
	// green "on" would be a lie. Warn (any channel), else on, else off.
	//
	// B075: $failures is newest-first; collect the latest entry per distinct channel
	// label so a chatty single channel cannot evict a second broken channel from view.
	$failures = get_option( 'fatalguard_delivery_failures', array() );
	$failures = is_array( $failures ) ? $failures : array();

	$failures_by_channel = array();
	foreach ( $failures as $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}
		$label = (string) ( $entry['channel'] ?? '' );
		if ( ! isset( $failures_by_channel[ $label ] ) ) {
			$failures_by_channel[ $label ] = $entry; // newest-first: first seen wins
		}
	}
	// Surface the most-recent failure across all channels (first in the keyed map).
	$latest_fail = ! empty( $failures_by_channel ) ? reset( $failures_by_channel ) : null;

	if ( is_array( $latest_fail ) ) {
		$chan   = (string) ( $latest_fail['channel'] ?? __( 'Alert', 'fatalguard' ) );
		$detail = (string) ( $latest_fail['detail'] ?? '' );
		$detail = preg_replace( '/^weekly health check:\s*/i', '', $detail ); // widget is terse
		echo '<p class="fg-dash__status warn"><span class="fg-dash__dot"></span>'
			. sprintf(
				/* translators: %s: channel name, e.g. Telegram. */
				esc_html__( '%s alerts are failing.', 'fatalguard' ),
				esc_html( $chan )
			);
		if ( '' !== $detail ) {
			echo ' <span class="fg-dash__failmsg">' . esc_html( fatalguard_dashboard_trim( $detail, 80 ) ) . '</span>';
		}
		echo ' <a href="' . esc_url( $settings . '#alerts' ) . '">' . esc_html__( 'Fix it', 'fatalguard' ) . '</a></p>';
	} elseif ( $connected ) {
		echo '<p class="fg-dash__status on"><span class="fg-dash__dot"></span>'
			. esc_html__( 'Telegram alerts are on.', 'fatalguard' ) . '</p>';
	} else {
		echo '<p class="fg-dash__status off"><span class="fg-dash__dot"></span>'
			. esc_html__( 'Alerts are off — a fatal error would go unnoticed.', 'fatalguard' )
			. ' <a href="' . esc_url( $settings . '#alerts' ) . '">' . esc_html__( 'Turn on alerts', 'fatalguard' ) . '</a></p>';
	}

	// B007/B123: use fatalguard_capture_active() to guard the "All clear" readout.
	// This checks dropin ownership, DISABLE kill-switch, and fatal_handler_enabled.
	$cap_active = function_exists( 'fatalguard_capture_active' ) ? fatalguard_capture_active() : false;

	// When capture is broken, show a distinct warning with a Re-arm recovery hint.
	if ( ! $cap_active ) {
		$disabled = ( function_exists( 'fatalguard_is_disabled' ) && fatalguard_is_disabled() )
			|| ( function_exists( 'gutenx_debug_logs_is_disabled' ) && gutenx_debug_logs_is_disabled() );
		$foreign  = ( function_exists( 'fatalguard_foreign_dropin_present' ) && fatalguard_foreign_dropin_present() )
			|| ( function_exists( 'gutenx_debug_logs_foreign_dropin_present' ) && gutenx_debug_logs_foreign_dropin_present() );
		if ( $disabled ) {
			echo '<div class="fg-dash__clear fg-dash__warn">⚠ '
				. esc_html__( 'Capture disabled (kill-switch active).', 'fatalguard' )
				. ' <a href="' . esc_url( $settings ) . '">' . esc_html__( 'Re-arm in FatalGuard settings', 'fatalguard' ) . '</a></div>';
		} elseif ( $foreign ) {
			echo '<div class="fg-dash__clear fg-dash__warn">⚠ '
				. esc_html__( 'Not capturing — another fatal-error handler is installed. FatalGuard cannot record fatals.', 'fatalguard' ) . '</div>';
		} else {
			echo '<div class="fg-dash__clear fg-dash__warn">⚠ '
				. esc_html__( 'Not capturing — drop-in not installed. FatalGuard cannot record fatals.', 'fatalguard' )
				. ' <a href="' . esc_url( $settings ) . '">' . esc_html__( 'Re-arm', 'fatalguard' ) . '</a></div>';
		}
	}

	// Latest error, or an all-clear (only when capture is active).
	if ( null === $latest ) {
		if ( $cap_active ) {
			echo '<div class="fg-dash__clear">✓ ' . esc_html__( 'No fatal errors captured. All clear.', 'fatalguard' ) . '</div>';
		}
	} else {
		$when   = fatalguard_dashboard_when( (string) ( $latest['time'] ?? '' ) );
		$label  = (string) ( $latest['type_label'] ?? __( 'Fatal error', 'fatalguard' ) );
		$msg    = (string) ( $latest['message'] ?? '' );
		$file   = (string) ( $latest['file'] ?? '' );
		$line   = (int) ( $latest['line'] ?? 0 );
		$origin = fatalguard_dashboard_origin( $latest['origin'] ?? null );

		echo '<div class="fg-dash__err">';
		echo '<div class="fg-dash__err-top"><span class="fg-dash__tag">' . esc_html( $label ) . '</span>';
		if ( '' !== $when ) {
			echo '<span class="fg-dash__when">' . esc_html( $when ) . '</span>';
		}
		echo '</div>';
		echo '<p class="fg-dash__msg">' . esc_html( fatalguard_dashboard_trim( $msg, 160 ) ) . '</p>';
		$loc = $file . ( $line ? ':' . $line : '' );
		if ( '' !== $loc || '' !== $origin ) {
			echo '<p class="fg-dash__loc">';
			if ( '' !== $loc ) {
				echo '<code>' . esc_html( $loc ) . '</code>';
			}
			if ( '' !== $origin ) {
				echo ' <span class="fg-dash__origin">' . esc_html( $origin ) . '</span>';
			}
			echo '</p>';
		}
		echo '</div>';
	}

	echo '<p class="fg-dash__more"><a href="' . esc_url( $settings ) . '">' . esc_html__( 'Open FatalGuard', 'fatalguard' ) . ' →</a></p>';

	// Pro nudge — only for free installs; hide from paying Pro customers (B153/B167/B185).
	if ( ! function_exists( 'fatalguard_can_use_pro' ) || ! fatalguard_can_use_pro() ) {
		echo '<div class="fg-dash__pro">';
		echo '<div class="fg-dash__pro-txt"><strong>' . esc_html__( 'Never miss one.', 'fatalguard' ) . '</strong> '
			. esc_html__( 'Pro adds Slack, email and Discord alerts, per-plugin routing, and a weekly digest.', 'fatalguard' ) . '</div>';
		echo '<a class="fg-dash__pro-btn" href="https://fatalguard.com/pro/" target="_blank" rel="noopener">'
			. esc_html__( 'Go Pro', 'fatalguard' ) . '</a>';
		echo '</div>';
	}

	echo '</div>';
}

/** The single most-recent captured fatal, or null. */
function fatalguard_dashboard_latest() {
	if ( ! function_exists( 'fatalguard_read_fatals' ) ) {
		return null;
	}
	$fatals = fatalguard_read_fatals( 1 );
	return ! empty( $fatals ) && is_array( $fatals[0] ) ? $fatals[0] : null;
}

/** Human "3 hours ago" from an ISO-8601 timestamp. */
function fatalguard_dashboard_when( string $iso ): string {
	if ( '' === $iso ) {
		return '';
	}
	$ts = strtotime( $iso );
	if ( ! $ts ) {
		return '';
	}
	/* translators: %s: human-readable time difference, e.g. "3 hours". */
	return sprintf( __( '%s ago', 'fatalguard' ), human_time_diff( $ts, time() ) );
}

/** A readable origin label from the classifier's shape (array or string). */
function fatalguard_dashboard_origin( $origin ): string {
	if ( is_array( $origin ) ) {
		$name = (string) ( $origin['name'] ?? $origin['slug'] ?? '' );
		$type = (string) ( $origin['type'] ?? '' );
		if ( '' !== $name ) {
			return '' !== $type ? $type . ': ' . $name : $name;
		}
		return '';
	}
	return (string) $origin;
}

/**
 * Trim to a character-length boundary with an ellipsis.
 *
 * Uses mb_strlen/mb_substr so a multibyte character (UTF-8 umlaut, CJK, Cyrillic,
 * emoji) is never split mid-sequence — byte-level truncation would produce invalid
 * UTF-8 which esc_html() then strips to an empty string (B094/B102/B184).
 */
function fatalguard_dashboard_trim( string $s, int $max ): string {
	$s = trim( preg_replace( '/\s+/', ' ', $s ) );
	if ( mb_strlen( $s ) <= $max ) {
		return $s;
	}
	return rtrim( mb_substr( $s, 0, $max ), " \t\n\r\0\x0B.," ) . '…';
}

