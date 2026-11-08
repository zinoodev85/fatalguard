<?php
/**
 * Admin-bar entry + in-place overlay. Admins only, wherever the admin bar shows
 * (front-end and wp-admin). The node toggles the overlay client-side; all data
 * comes over REST. Only logged-in admin-bar views are rendered, so the nonce
 * never enters publicly-cached front-end HTML.
 *
 * @package FatalGuard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The shield mark, monochrome (currentColor) so it reads BW on the dark admin bar. */
function fatalguard_brand_icon( int $px = 18 ): string {
	return '<svg viewBox="0 0 24 24" width="' . $px . '" height="' . $px . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
		. '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>'
		. '<path d="m9 12 2 2 4-4"/></svg>';
}

/** kses allowlist for the brand icon — lets wp_kses() pass the SVG through at every sink. */
function fatalguard_brand_icon_kses(): array {
	return array(
		'svg'  => array(
			'viewbox'         => true,
			'width'           => true,
			'height'          => true,
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
			'aria-hidden'     => true,
			'focusable'       => true,
		),
		'path' => array( 'd' => true ),
	);
}

/** Add the "Logs" admin-bar item (cap check is mandatory — the hook fires for any logged-in user). */
function fatalguard_admin_bar( $wp_admin_bar ): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$wp_admin_bar->add_node(
		array(
			'id'    => 'fatalguard',
			'title' => '<span class="ab-icon">' . wp_kses( fatalguard_brand_icon( 16 ), fatalguard_brand_icon_kses() ) . '</span>'
				. '<span class="ab-label">' . esc_html__( 'Logs', 'fatalguard' ) . '</span>',
			'href'  => '#',
			'meta'  => array(
				// Click behaviour lives in overlay.js (bound to this node's id) — no inline JS.
				'title' => __( 'Debug Logs', 'fatalguard' ),
			),
		)
	);
}

/** Enqueue overlay assets wherever the admin bar shows (front-end + admin). */
function fatalguard_enqueue( $hook = '' ): void {
	if ( ! current_user_can( 'manage_options' ) || ! is_admin_bar_showing() ) {
		return;
	}
	$base = plugins_url( 'assets/', __FILE__ );
	$ver  = defined( 'FATALGUARD_DL_VERSION' ) ? FATALGUARD_DL_VERSION : '1';

	wp_enqueue_style( 'fatalguard', $base . 'overlay.css', array(), $ver );
	wp_enqueue_script( 'fatalguard-classify', $base . 'classify.js', array(), $ver, true );
	wp_enqueue_script( 'fatalguard', $base . 'overlay.js', array( 'wp-api-fetch', 'wp-i18n', 'fatalguard-classify' ), $ver, true );
	wp_set_script_translations( 'fatalguard', 'fatalguard' );
	wp_localize_script(
		'fatalguard',
		'fatalguardDebugLogsData',
		array(
			'namespace' => 'fatalguard/v1/debug-logs',
			'version'   => $ver,
		)
	);
}

/** Print the overlay shell into the footer (built out client-side). */
function fatalguard_render_overlay(): void {
	if ( ! current_user_can( 'manage_options' ) || ! is_admin_bar_showing() ) {
		return;
	}
	?>
	<div id="fatalguard-overlay" hidden aria-hidden="true">
		<div id="fatalguard-head">
			<strong id="fatalguard-title"><span class="fatalguard-ico" aria-hidden="true"><?php echo wp_kses( fatalguard_brand_icon( 15 ), fatalguard_brand_icon_kses() ); ?></span><span class="fatalguard-titletext"><?php echo esc_html__( 'Debug Logs', 'fatalguard' ); ?></span></strong>
			<nav id="fatalguard-tabs" role="tablist"></nav>
			<span id="fatalguard-peek" class="fatalguard-spacer" aria-live="polite"></span>
			<button type="button" data-act="min" aria-pressed="false" aria-label="<?php echo esc_attr__( 'Lower', 'fatalguard' ); ?>" title="<?php echo esc_attr__( 'Lower (collapse to a bar)', 'fatalguard' ); ?>">⌄</button>
			<button type="button" data-act="close" aria-label="<?php echo esc_attr__( 'Close', 'fatalguard' ); ?>" title="<?php echo esc_attr__( 'Close', 'fatalguard' ); ?>">✕</button>
		</div>
		<div id="fatalguard-body" role="tabpanel" aria-labelledby="fatalguard-tab-0"><div class="fatalguard-loading"><?php echo esc_html__( 'Loading…', 'fatalguard' ); ?></div></div>
		<div id="fatalguard-foot">
			<div id="fatalguard-filters"></div>
			<span class="fatalguard-foot-spacer"></span>
			<button type="button" data-act="live" aria-pressed="false" title="<?php echo esc_attr__( 'Auto-refresh the view every 2 seconds', 'fatalguard' ); ?>"><?php echo esc_html__( 'Live: Off', 'fatalguard' ); ?></button>
			<button type="button" data-act="refresh" title="<?php echo esc_attr__( 'Reload this log now', 'fatalguard' ); ?>"><?php echo esc_html__( 'Refresh', 'fatalguard' ); ?></button>
			<button type="button" data-act="clear" title="<?php echo esc_attr__( 'Empty the current log or fatals list', 'fatalguard' ); ?>"><?php echo esc_html__( 'Clear', 'fatalguard' ); ?></button>
		</div>
	</div>
	<?php
}
