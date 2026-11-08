<?php
/**
 * FatalGuard inline icons — one small line-icon set (Lucide, ISC-licensed) drawn
 * in currentColor so a mark inherits the color + size (1em) of whatever wraps it.
 * Used for the settings hero, the Pro teaser grid, and the header shield.
 *
 * @package FatalGuard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return an inline SVG for a known key, or '' when unknown. Output is a fixed set
 * of trusted, static markup (safe to echo without escaping).
 */
function fatalguard_icon( string $key ): string {
	$paths = array(
		'shield'    => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
		'telegram'  => '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
		'slack'     => '<line x1="4" x2="20" y1="9" y2="9"/><line x1="4" x2="20" y1="15" y2="15"/><line x1="10" x2="8" y1="3" y2="21"/><line x1="16" x2="14" y1="3" y2="21"/>',
		'discord'   => '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/><path d="M9.5 11h.01"/><path d="M14.5 11h.01"/>',
		'email'     => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
		'webhook'   => '<path d="M18 16.98h-5.99c-1.1 0-1.95.94-2.48 1.9A4 4 0 0 1 2 17c.01-.7.2-1.4.57-2"/><path d="m6 17 3.13-5.78c.53-.97.1-2.18-.5-3.1a4 4 0 1 1 6.89-4.06"/><path d="m12 6 3.13 5.73C15.66 12.7 16.9 13 18 13a4 4 0 0 1 0 8"/>',
		'routing'   => '<path d="M2 18h1.4c1.3 0 2.5-.6 3.3-1.7l6.1-8.6c.7-1.1 2-1.7 3.3-1.7H22"/><path d="m18 2 4 4-4 4"/><path d="M2 6h1.9c1.5 0 2.9.9 3.6 2.2"/><path d="M22 18h-5.9c-1.3 0-2.6-.7-3.3-1.8l-.5-.8"/><path d="m18 14 4 4-4 4"/>',
		'stealth'   => '<path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" x2="22" y1="2" y2="22"/>',
		'digest'    => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/><path d="M8 14h.01"/><path d="M12 14h4"/><path d="M8 18h.01"/><path d="M12 18h4"/>',
		'history'   => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
		'mcp'       => '<path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/>',
	);
	if ( ! isset( $paths[ $key ] ) ) {
		return '';
	}
	return '<svg class="fg-svg" viewBox="0 0 24 24" width="1.05em" height="1.05em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $key ] . '</svg>';
}

/** kses allowlist matching the icon markup above — pass it to wp_kses() at each echo. */
function fatalguard_icon_kses(): array {
	return array(
		'svg'    => array(
			'class'           => true,
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
		'path'   => array( 'd' => true ),
		'rect'   => array(
			'width'  => true,
			'height' => true,
			'x'      => true,
			'y'      => true,
			'rx'     => true,
		),
		'line'   => array(
			'x1' => true,
			'x2' => true,
			'y1' => true,
			'y2' => true,
		),
		'circle' => array(
			'cx' => true,
			'cy' => true,
			'r'  => true,
		),
	);
}
