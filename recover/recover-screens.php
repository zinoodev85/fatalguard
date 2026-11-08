<?php
/**
 * FatalGuard recovery — the standalone pages (landing / restored / expired).
 * Self-contained HTML: the site may be broken, so nothing external loads. Each
 * renderer prints a full document and exits. Ported from the approved
 * emergency-console mock (dark, monospace incident readout, one clear action).
 *
 * Escaping model: these screens render from the recovery mu-loader, AFTER
 * wp-includes is fully loaded (wp-settings.php loads it before mu-plugins run),
 * so the core escapers are always available and every dynamic value is escaped
 * AT the echo with esc_html()/esc_attr()/esc_url(), and the static first-party
 * SVGs pass through wp_kses() with an explicit allowlist.
 *
 * @package FatalGuard
 */

defined( 'ABSPATH' ) || exit;

/** kses allowlist for the static first-party SVG icons below. */
function fatalguard_recover_svg_kses(): array {
	return array(
		'svg'    => array(
			'viewbox'         => true,
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
		),
		'path'   => array(
			'd'            => true,
			'stroke-width' => true,
			'fill'         => true,
			'stroke'       => true,
		),
		'rect'   => array(
			'x'      => true,
			'y'      => true,
			'width'  => true,
			'height' => true,
			'rx'     => true,
			'fill'   => true,
			'stroke' => true,
		),
		'circle' => array(
			'cx'     => true,
			'cy'     => true,
			'r'      => true,
			'fill'   => true,
			'stroke' => true,
		),
	);
}

/** SVG icon markup (static first-party literals; escaped with wp_kses at each echo). */
function fatalguard_recover_svg( string $k ): string {
	$m = array(
		'power'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v10"/><path d="M18.4 6.6a9 9 0 1 1-12.77.04"/></svg>',
		'check'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
		// The FatalGuard captured-"!" mark (single-colour, inherits the amber brand
		// colour) — the error held in a capture frame. Consistent with the plugin icon.
		'shield' => '<svg viewBox="0 0 100 100" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M27 42 V27 H42" stroke-width="7"/><path d="M73 58 V73 H58" stroke-width="7"/><rect x="45" y="33" width="10" height="25" rx="5" fill="currentColor" stroke="none"/><circle cx="50" cy="66" r="6" fill="currentColor" stroke="none"/></svg>',
	);
	return $m[ $k ] ?? '';
}

/**
 * Print the shared document head + CSS + brand row. $chip = the incident chip
 * (amber) or a resolved chip. Static markup echoes as literals; every dynamic
 * value is escaped at its echo.
 */
function fatalguard_recover_head( string $chip = 'INCIDENT · FATAL', string $chipClass = 'inc' ): void {
	$css = '
	:root{--bg:#0f1216;--panel:#171b21;--panel-2:#1d2229;--line:#262c34;--line-2:#323a44;
	--ink:#E8ECF1;--muted:#8a94a2;--faint:#5f6b78;--amber:#F5A623;--amber-d:#E39412;--amber-ink:#3a2a05;
	--red:#F0616A;--green:#34C77B;--sans:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
	--mono:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
	*{box-sizing:border-box}html,body{height:100%}
	body{margin:0;background:radial-gradient(1200px 600px at 50% -10%,#1a2029 0%,var(--bg) 55%);
	color:var(--ink);font:15px/1.55 var(--sans);-webkit-font-smoothing:antialiased;
	display:flex;align-items:center;justify-content:center;padding:22px}
	.card{width:min(460px,100%);background:var(--panel);border:1px solid var(--line);border-radius:16px;
	overflow:hidden;box-shadow:0 24px 70px rgba(0,0,0,.5)}
	.accent{height:3px;background:linear-gradient(90deg,var(--amber),#ffcf7a,var(--amber-d))}
	.pad{padding:22px 22px 24px}
	.brand{display:flex;align-items:center;gap:9px;color:var(--muted);font:600 12px/1 var(--sans);letter-spacing:.04em;text-transform:uppercase}
	.brand svg{width:17px;height:17px;color:var(--amber)}.brand b{color:var(--ink);font-weight:700}
	.chip{margin-left:auto;font-family:var(--mono);font-size:11px;padding:2px 8px;border-radius:6px;letter-spacing:.02em}
	.chip.inc{color:var(--amber);border:1px solid #4a3a12;background:#231a08}
	.chip.ok{color:var(--green);border:1px solid #1c4b31;background:#0e2318}
	h1{margin:16px 0 4px;font-size:21px;letter-spacing:-.02em;line-height:1.2}
	.lede{margin:0 0 18px;color:var(--muted);font-size:14px}
	.readout{background:var(--panel-2);border:1px solid var(--line);border-left:3px solid var(--amber);
	border-radius:10px;padding:13px 14px;margin:0 0 18px;font-family:var(--mono);font-size:12.5px}
	.readout .r{display:flex;gap:10px;padding:3px 0}
	.readout .k{color:var(--faint);flex:none;width:58px;text-transform:uppercase;font-size:11px;letter-spacing:.03em;padding-top:1px}
	.readout .v{color:var(--ink);word-break:break-word}
	.readout .culprit{color:#fff;font-weight:700;font-family:var(--sans);font-size:13.5px}
	.readout .ok{color:var(--green)}
	.btn{display:flex;align-items:center;justify-content:center;gap:9px;width:100%;border:0;border-radius:11px;
	padding:14px 16px;font:700 15px/1 var(--sans);cursor:pointer;text-decoration:none}
	.btn.primary{background:var(--amber);color:var(--amber-ink)}.btn.primary:hover{background:var(--amber-d)}
	.btn.green{background:var(--green);color:#04220f}
	.btn.ghost{background:transparent;color:var(--muted);border:1px solid var(--line-2);margin-top:9px;font-weight:600}
	.btn.ghost:hover{color:var(--ink);border-color:var(--faint)}
	.btn svg{width:17px;height:17px}
	.reassure{display:flex;gap:8px;margin:12px 2px 0;color:var(--muted);font-size:12.5px}
	.reassure svg{width:15px;height:15px;color:var(--green);flex:none;margin-top:1px}
	.foot{margin:18px 2px 0;color:var(--faint);font-size:11.5px;font-family:var(--mono);display:flex;gap:7px;flex-wrap:wrap}
	.center{text-align:center}
	.culprit-name{color:#fff;font-weight:700}
	.ok-badge{width:56px;height:56px;border-radius:50%;background:rgba(52,199,123,.13);display:grid;place-items:center;margin:4px auto 14px}
	.ok-badge svg{width:30px;height:30px;color:var(--green)}
	form{margin:0}';
	// B190: use the site's configured locale for the html lang attribute so screen
	// readers and browsers handle non-English emergency pages correctly.
	$lang = function_exists( 'get_locale' ) ? str_replace( '_', '-', get_locale() ) : 'en';
	// This is a complete standalone HTML document served from the recovery mu-loader
	// (wp-admin is down; wp_head never runs for this request), so the stylesheet is
	// attached as an inline style through the core styles API and printed at the only
	// point in this request where a <head> exists. wp-includes is fully loaded before
	// mu-plugins run, so the API is always available. Registered on the WP_Styles
	// object directly: the wp_register_style()/wp_enqueue_style() wrappers fire a
	// pre-init _doing_it_wrong() notice that would print ABOVE the doctype on
	// WP_DEBUG_DISPLAY sites — this is a pre-init emergency page by design.
	// $css is a static literal.
	wp_styles()->add( 'fatalguard-recover', false, array(), null );
	wp_add_inline_style( 'fatalguard-recover', $css );

	echo '<!doctype html><html lang="' . esc_attr( $lang ) . '"><head><meta charset="utf-8">';
	echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
	echo '<meta name="robots" content="noindex">';
	// B190: i18n — the page title is translatable.
	echo '<title>' . esc_html__( 'FatalGuard — recovery', 'fatalguard' ) . '</title>';
	wp_print_styles( array( 'fatalguard-recover' ) );
	echo '</head><body>';
	echo '<div class="card"><div class="accent"></div><div class="pad">';
	echo '<div class="brand">' . wp_kses( fatalguard_recover_svg( 'shield' ), fatalguard_recover_svg_kses() ) . '<span><b>FatalGuard</b> · Recovery</span>';
	echo '<span class="chip ' . esc_attr( $chipClass ) . '">' . esc_html( $chip ) . '</span></div>';
}

/** Print the shared document foot (static literal). */
function fatalguard_recover_foot(): void {
	echo '</div></div></body></html>';
}

/**
 * Landing: name the culprit + one deactivate button (POST back to this same URL).
 *
 * B088: accepts $issued so we can display how long ago the fatal was captured;
 * softened lede so admins aren't panic-deactivating for one-off/stale errors;
 * adds a "Check your site first" ghost link to home_url.
 *
 * @param string $slug   The plugin slug.
 * @param string $name   The human-readable plugin name.
 * @param string $token  The raw recovery token (for the hidden form field).
 * @param int    $issued Unix timestamp when the recovery link was minted.
 */
function fatalguard_recover_screen_landing( string $slug, string $name, string $token, int $issued = 0 ): void {
	// B088: show how long ago the fatal was captured so admins can judge urgency.
	$ago_text = '';
	if ( $issued > 0 ) {
		$mins     = max( 0, (int) round( ( time() - $issued ) / 60 ) );
		$ago_text = 0 === $mins
			? ' ' . __( 'just now', 'fatalguard' )
			/* translators: %d: number of minutes since the fatal was captured. */
			: ' ' . sprintf( _n( '%d min ago', '%d mins ago', $mins, 'fatalguard' ), $mins );
	}

	fatalguard_recover_head();
	echo '<h1>' . esc_html__( 'Your site hit a fatal error', 'fatalguard' ) . '</h1>';
	printf(
		/* translators: 1: plugin name (bold), 2: how long ago (e.g. "5 mins ago"). */
		'<p class="lede">' . esc_html__( 'FatalGuard captured a fatal error in %1$s%2$s. Deactivating it may restore the site — check the site first if you\'re unsure.', 'fatalguard' ) . '</p>',
		'<b class="culprit-name">' . esc_html( $name ) . '</b>',
		esc_html( $ago_text )
	);
	// B152: single-file plugins (no '/' in slug) live in the plugins root as a
	// .php file, not inside a sub-folder. Use 'Plugin file' for those; 'Folder'
	// only when the entry is the folder/main.php form.
	$is_folder  = strpos( $slug, '/' ) !== false;
	$path_label = $is_folder ? __( 'Folder', 'fatalguard' ) : __( 'Plugin file', 'fatalguard' );
	echo '<div class="readout">';
	echo '<div class="r"><span class="k">' . esc_html__( 'Plugin', 'fatalguard' ) . '</span><span class="v culprit">' . esc_html( $name ) . '</span></div>';
	echo '<div class="r"><span class="k">' . esc_html( $path_label ) . '</span><span class="v">' . esc_html( $slug ) . '</span></div>';
	echo '</div>';
	echo '<form method="post" action="">';
	echo '<input type="hidden" name="fatalguard_confirm" value="1">';
	echo '<button class="btn primary" type="submit">' . wp_kses( fatalguard_recover_svg( 'power' ), fatalguard_recover_svg_kses() ) . ' '
		/* translators: %s: plugin name. */
		. esc_html( sprintf( __( 'Deactivate %s', 'fatalguard' ), fatalguard_recover_pretty( $slug ) ) ) . '</button>';
	echo '</form>';
	// B088: let the admin verify the site is actually still down before deactivating.
	$site = function_exists( 'home_url' ) ? home_url( '/' ) : '/';
	echo '<a class="btn ghost" href="' . esc_url( $site ) . '" target="_blank" rel="noopener">' . esc_html__( 'Check your site first', 'fatalguard' ) . '</a>';
	echo '<div class="reassure">' . wp_kses( fatalguard_recover_svg( 'check' ), fatalguard_recover_svg_kses() ) . '<span>' . esc_html__( 'Just turns the plugin off so your pages load. Nothing is deleted — re-enable it anytime from Plugins once it\'s fixed.', 'fatalguard' ) . '</span></div>';
	echo '<div class="foot">🔒 ' . esc_html__( 'Secure recovery link · expires in 4 hours · signed by FatalGuard', 'fatalguard' ) . '</div>';
	fatalguard_recover_foot();
	exit;
}

/**
 * Restored (or already-inactive) screen.
 *
 * B087: h1/lede are now driven by $healthy so the copy accurately reflects
 * reality — the success h1 only appears when the healthcheck confirms success.
 * B150: the healthcheck-result label qualifies what was probed (homepage) so
 * the admin knows the actual crash URL was not re-tested.
 *
 * @param string $slug        The plugin slug.
 * @param string $name        Human-readable plugin name.
 * @param ?bool  $healthy     Healthcheck result: true=ok, false=still down, null=unknown.
 * @param bool   $deactivated Whether the plugin was deactivated in this request.
 */
function fatalguard_recover_screen_done( string $slug, string $name, ?bool $healthy, bool $deactivated = true ): void {
	fatalguard_recover_head( __( 'RESOLVED', 'fatalguard' ), 'ok' );
	echo '<div class="ok-badge">' . wp_kses( fatalguard_recover_svg( 'check' ), fatalguard_recover_svg_kses() ) . '</div>';
	if ( ! $deactivated ) {
		// Plugin was already inactive — site may or may not be down.
		echo '<h1 class="center">' . esc_html__( 'Plugin already inactive', 'fatalguard' ) . '</h1>';
		/* translators: %s: plugin name (bold). */
		printf( '<p class="lede center">' . esc_html__( '%s wasn\'t active, so nothing changed. If your site is still down, another plugin may be the cause.', 'fatalguard' ) . '</p>', '<b class="culprit-name">' . esc_html( $name ) . '</b>' );
	} elseif ( false === $healthy ) {
		// Deactivated but site is still erroring.
		echo '<h1 class="center">' . esc_html__( 'Plugin turned off — site still erroring', 'fatalguard' ) . '</h1>';
		/* translators: %s: plugin name (bold). */
		printf( '<p class="lede center">' . esc_html__( '%s is deactivated. Another plugin may still be involved — check your site and expect a follow-up alert.', 'fatalguard' ) . '</p>', '<b class="culprit-name">' . esc_html( $name ) . '</b>' );
	} elseif ( null === $healthy ) {
		// Deactivated but healthcheck was inconclusive (loopback blocked).
		echo '<h1 class="center">' . esc_html__( 'Plugin turned off — check your site', 'fatalguard' ) . '</h1>';
		/* translators: %s: plugin name (bold). */
		printf( '<p class="lede center">' . esc_html__( '%s is deactivated. Open your site to confirm it\'s back up.', 'fatalguard' ) . '</p>', '<b class="culprit-name">' . esc_html( $name ) . '</b>' );
	} elseif ( true === $healthy ) {
		// B087: success h1 only when healthcheck confirms the site is up.
		echo '<h1 class="center">' . esc_html__( 'Site restored', 'fatalguard' ) . '</h1>';
		/* translators: %s: plugin name (bold). */
		printf( '<p class="lede center">' . esc_html__( '%s is deactivated. Your pages load again.', 'fatalguard' ) . '</p>', '<b class="culprit-name">' . esc_html( $name ) . '</b>' );
	}
	echo '<div class="readout">';
	$status_key = $deactivated ? __( 'Off', 'fatalguard' ) : __( 'Status', 'fatalguard' );
	$status_val = $deactivated ? $name : $name . ' · ' . __( 'already off', 'fatalguard' );
	echo '<div class="r"><span class="k">' . esc_html( $status_key ) . '</span><span class="v culprit">' . esc_html( $status_val ) . '</span></div>';
	// B150: qualify the probe target — homepage, not the actual crash URL.
	if ( true === $healthy ) {
		echo '<div class="r"><span class="k">' . esc_html__( 'Site', 'fatalguard' ) . '</span><span class="v ok">' . esc_html__( 'homepage responds (the failing page was not re-tested)', 'fatalguard' ) . '</span></div>';
	} elseif ( false === $healthy ) {
		echo '<div class="r"><span class="k">' . esc_html__( 'Site', 'fatalguard' ) . '</span><span class="v">' . esc_html__( 'still erroring — another plugin may be involved', 'fatalguard' ) . '</span></div>';
	}
	echo '</div>';
	$site    = function_exists( 'home_url' ) ? home_url( '/' ) : '/';
	$plugins = function_exists( 'admin_url' ) ? admin_url( 'plugins.php' ) : '/wp-admin/plugins.php';
	echo '<a class="btn green" href="' . esc_url( $site ) . '">' . esc_html__( 'Visit your site', 'fatalguard' ) . '</a>';
	echo '<a class="btn ghost" href="' . esc_url( $plugins ) . '">' . esc_html__( 'Open Plugins to update or replace it', 'fatalguard' ) . '</a>';
	echo '<div class="foot">🔒 ' . esc_html__( 'Re-enable it once you\'ve updated or replaced it.', 'fatalguard' ) . '</div>';
	fatalguard_recover_foot();
	exit;
}

/**
 * Expired: link too old. Offer a secure fresh-link request form + the manual admin route.
 *
 * B085: the $token is the ORIGINAL signed (but expired) token. The renewal form posts it
 * back; the router (fatalguard_recover_run) re-verifies its signature — an expired token
 * still proves authorisation for THIS slug, while a forged/other-slug token fails the
 * signature check and is refused — then mints a fresh link. So the affordance is not an
 * unauthenticated mint-any-slug hole. When no token is available the form is omitted.
 *
 * @param string $slug  The plugin slug.
 * @param string $token The original signed token (for the secure renewal POST).
 */
function fatalguard_recover_screen_expired( string $slug, string $token = '' ): void {
	$plugins = function_exists( 'admin_url' ) ? admin_url( 'plugins.php' ) : '/wp-admin/plugins.php';
	fatalguard_recover_head();
	echo '<h1>' . esc_html__( 'This recovery link has expired', 'fatalguard' ) . '</h1>';
	echo '<p class="lede">' . esc_html__( 'Recovery links are valid for 4 hours. The error is still captured — request a fresh link below, or deactivate manually from wp-admin.', 'fatalguard' ) . '</p>';
	echo '<div class="readout"><div class="r"><span class="k">' . esc_html__( 'Plugin', 'fatalguard' ) . '</span><span class="v culprit">' . esc_html( fatalguard_recover_pretty( $slug ) ) . '</span></div></div>';
	// B085: secure in-screen renewal — re-verified server-side against the token's signature.
	if ( '' !== $token ) {
		echo '<form method="post" action="">';
		echo '<input type="hidden" name="fatalguard_renew" value="1">';
		echo '<input type="hidden" name="fatalguard_token" value="' . esc_attr( $token ) . '">';
		echo '<button class="btn primary" type="submit">' . esc_html__( 'Request a new recovery link', 'fatalguard' ) . '</button>';
		echo '</form>';
	}
	echo '<a class="btn ghost" href="' . esc_url( $plugins ) . '">' . esc_html__( 'Deactivate from Plugins instead', 'fatalguard' ) . '</a>';
	echo '<div class="foot">🔒 ' . esc_html__( 'Links are single-use and signed · FatalGuard', 'fatalguard' ) . '</div>';
	fatalguard_recover_foot();
	exit;
}
