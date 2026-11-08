<?php
/**
 * FatalGuard — review ask. A dismissible admin prompt inviting a wordpress.org
 * review, shown only AFTER the plugin has proven value: the first fatal is
 * captured, OR two weeks after first activation. No incentive (wp.org rule);
 * fully dismissible (persisted via "Maybe later" + the native notice X).
 *
 * Scope: renders ONLY on the FatalGuard screen (toplevel_page_fatalguard), never
 * on other admin pages — wp.org Guideline 11 (no admin-dashboard hijacking).
 *
 * @package FatalGuard
 */

defined( 'ABSPATH' ) || exit;

/** Stamp first-seen time once, and handle a persisted dismissal. */
add_action( 'admin_init', 'fatalguard_review_bootstrap' );
function fatalguard_review_bootstrap(): void {
	if ( ! get_option( 'fatalguard_activated_at' ) ) {
		update_option( 'fatalguard_activated_at', time(), false );
	}
	if ( isset( $_GET['fatalguard_review_dismiss'] ) && current_user_can( 'manage_options' ) && check_admin_referer( 'fatalguard_review_dismiss' ) ) {
		update_option( 'fatalguard_review_dismissed', 1, false );
		wp_safe_redirect( remove_query_arg( array( 'fatalguard_review_dismiss', '_wpnonce' ) ) );
		exit;
	}
}

/** Whether to show the review ask now. */
function fatalguard_review_should_ask(): bool {
	if ( ! current_user_can( 'manage_options' ) || get_option( 'fatalguard_review_dismissed' ) ) {
		return false;
	}
	if ( function_exists( 'fatalguard_stealth_hidden' ) && fatalguard_stealth_hidden() ) {
		return false;
	}
	$has_fatal = function_exists( 'fatalguard_read_fatals' ) && ! empty( fatalguard_read_fatals( 1 ) );
	$activated = (int) get_option( 'fatalguard_activated_at', time() );
	$aged      = defined( 'WEEK_IN_SECONDS' ) && ( time() - $activated ) >= 2 * WEEK_IN_SECONDS;
	return $has_fatal || $aged;
}

/** Enqueue the review-notice styles + dismiss-persistence script, only on our screen when the notice will show. */
add_action( 'admin_enqueue_scripts', 'fatalguard_review_assets' );
function fatalguard_review_assets( $hook ): void {
	if ( 'toplevel_page_fatalguard' !== $hook || ! fatalguard_review_should_ask() ) {
		return;
	}
	wp_enqueue_style(
		'fatalguard-review-ask',
		plugins_url( 'assets/review-ask.css', FATALGUARD_FILE ),
		array(),
		FATALGUARD_VERSION
	);
	wp_enqueue_script(
		'fatalguard-review-ask',
		plugins_url( 'assets/review-ask.js', FATALGUARD_FILE ),
		array(),
		FATALGUARD_VERSION,
		true
	);
	wp_localize_script(
		'fatalguard-review-ask',
		'fatalguardReviewL10n',
		array(
			'dismissUrl' => wp_nonce_url( add_query_arg( 'fatalguard_review_dismiss', 1 ), 'fatalguard_review_dismiss' ),
		)
	);
}

add_action( 'admin_notices', 'fatalguard_review_notice' );
function fatalguard_review_notice(): void {
	// Own-screen only (Guideline 11): never nag on unrelated admin pages.
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'toplevel_page_fatalguard' !== $screen->id ) {
		return;
	}
	if ( ! fatalguard_review_should_ask() ) {
		return;
	}
	$rate    = 'https://wordpress.org/support/plugin/fatalguard/reviews/#new-post';
	$dismiss = wp_nonce_url( add_query_arg( 'fatalguard_review_dismiss', 1 ), 'fatalguard_review_dismiss' );
	echo '<div class="notice notice-info is-dismissible fg-review"><p>';
	echo '<span class="fg-review__in">';
	echo '<span class="fg-review__stars" aria-hidden="true">★★★★★</span>';
	echo '<span class="fg-review__txt"><strong>' . esc_html__( 'Has FatalGuard caught a fatal for you yet?', 'fatalguard' ) . '</strong> ';
	echo esc_html__( 'If it saved you a headache, a quick review would mean a lot — and helps other developers find it.', 'fatalguard' ) . '</span>';
	echo '<span class="fg-review__act">';
	echo '<a class="button button-primary" href="' . esc_url( $rate ) . '" target="_blank" rel="noopener">' . esc_html__( 'Leave a review', 'fatalguard' ) . '</a>';
	echo '<a class="fg-review__later" href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Maybe later', 'fatalguard' ) . '</a>';
	echo '</span></span>';
	echo '</p></div>';
	// The dismiss-persistence script + styling ship as enqueued assets (assets/review-ask.js
	// + assets/review-ask.css, wired in fatalguard_review_assets()) — nothing inline here.
}
