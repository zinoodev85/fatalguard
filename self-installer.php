<?php
/**
 * Self-installer: idempotently heals the boot drop-in and the protected log
 * directory from the shipped template, honoring the DISABLE kill-switch and
 * refusing to clobber a third-party fatal-error-handler.php. Runs once per
 * request at module load (cheap: a stat + content compare).
 *
 * @package FatalGuard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- Installs/heals the fatal-error drop-in
// in wp-content with direct mkdir()/rename()/unlink() so recovery works during an emergency;
// WP_Filesystem may prompt for FS credentials (FTP), which is unusable when the site is down.

/** Absolute path WordPress includes as the fatal-error handler drop-in. */
function fatalguard_dropin_path(): string {
	// WP_CONTENT_DIR is always defined by wp-settings.php; the drop-in filename and
	// location are fixed by WordPress core's drop-in mechanism.
	return rtrim( WP_CONTENT_DIR, '/\\' ) . '/fatal-error-handler.php';
}

/** Marker that identifies OUR drop-in (so we never clobber someone else's). */
function fatalguard_dropin_marker(): string {
	return 'FatalGuard_DL_Dropin_Handler';
}

/** Resolve the theme secrets path to bake into the drop-in. */
function fatalguard_baked_secrets_path(): string {
	if ( function_exists( 'fatalguard_secrets_path' ) ) {
		$p = fatalguard_secrets_path();
		if ( '' !== $p ) {
			return $p;
		}
	}
	return ''; // FatalGuard: no theme secrets file — the drop-in reads creds from baked constants only.
}

/**
 * Cheap signature of every input baked into the drop-in (log dir + expected version).
 * Stored as the `fatalguard_dropin_ok` fast-path value so a change to any of them
 * (e.g. the upload path drifts, or a version bump) invalidates the fast-path and forces
 * a full compare + heal — the disk copy can never silently disagree with the live
 * plugin state. Boot-cheap: the dir is static-cached.
 */
function fatalguard_dropin_signature(): string {
	return md5( fatalguard_dir() . '|1.2.1' );
}

/** Render the drop-in bytes from the template with paths baked in. */
function fatalguard_render_dropin(): string {
	static $rendered = null;
	if ( null !== $rendered ) {
		return $rendered;
	}
	$template = @file_get_contents( __DIR__ . '/templates/dropin.php' );
	if ( false === $template ) {
		$rendered = '';
		return $rendered;
	}
	// The template bakes these paths into a nowdoc (<<<'ID') literal, which is
	// raw-safe: apostrophes and backslashes in the path (e.g. "/Users/x/Mati's
	// Sites/") pass through verbatim without escaping and without a ParseError
	// that would brick WP pre-boot (B001). Substitute the raw path — do NOT
	// addslashes, or the baked filesystem path would be wrong.
	// B174: bake the owning plugin directory so the drop-in can self-disable if
	// the plugin is FTP-deleted. Derived from __FILE__ via plugin_basename(), which
	// returns a plugins-dir-relative path (first segment = the plugin's root folder)
	// only when this file actually lives under the plugins dir. Only bake when the
	// derived dir is confirmed with is_dir(); otherwise leave the placeholder, which
	// the drop-in's guard treats as "unknown" and skips — never a false self-disable.
	$plugin_dir = '__FATALGUARD_PLUGIN_DIR__';
	$norm       = str_replace( '\\', '/', __FILE__ );
	$rel        = function_exists( 'plugin_basename' ) ? plugin_basename( __FILE__ ) : '';
	if ( '' !== $rel && $rel !== $norm && false !== strpos( $rel, '/' ) ) {
		$seg = explode( '/', $rel )[0];
		$pos = strrpos( $norm, '/' . $seg . '/' );
		if ( '' !== $seg && false !== $pos ) {
			$candidate = substr( $norm, 0, $pos ) . '/' . $seg;
			if ( is_dir( $candidate ) ) {
				$plugin_dir = $candidate;
			}
		}
	}
	$rendered = strtr(
		$template,
		array(
			'__FATALGUARD_DL_DIR__'          => fatalguard_dir(),
			'__FATALGUARD_DL_SECRETS__'      => fatalguard_baked_secrets_path(),
			'__FATALGUARD_PLUGIN_DIR__'  => $plugin_dir,
		)
	);
	return $rendered;
}

/** Atomic write (temp + rename), with a strict length check. */
function fatalguard_write_atomic( string $path, string $content ): bool {
	$dir = dirname( $path );
	if ( ! is_dir( $dir ) ) {
		if ( function_exists( 'wp_mkdir_p' ) ) {
			@wp_mkdir_p( $dir );
		} else {
			@mkdir( $dir, 0755, true );
		}
	}
	if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
		return false;
	}
	// Never write a drop-in that would fatal on include. WP core includes this
	// file pre-boot with no try/catch, so a ParseError here bricks every request
	// unrecoverably. Validate the rendered PHP first; bail without writing on any
	// parse failure (B001).
	try {
		token_get_all( $content, TOKEN_PARSE );
	} catch ( \ParseError $e ) {
		return false;
	}
	$tmp = $path . '.fatalguard-tmp.' . uniqid( '', true );
	if ( strlen( $content ) !== (int) @file_put_contents( $tmp, $content, LOCK_EX ) ) {
		@unlink( $tmp );
		return false;
	}
	if ( ! @rename( $tmp, $path ) ) {
		@unlink( $tmp );
		return false;
	}
	if ( function_exists( 'opcache_invalidate' ) ) {
		@opcache_invalidate( $path, true );
	}
	if ( function_exists( 'opcache_compile_file' ) ) {
		@opcache_compile_file( $path );
	}
	return true;
}

/** Create the private log dir + web-deny protections (idempotent). */
function fatalguard_ensure_logdir(): bool {
	$dir = fatalguard_dir();
	if ( ! is_dir( $dir ) ) {
		if ( function_exists( 'wp_mkdir_p' ) ) {
			@wp_mkdir_p( $dir );
		} else {
			@mkdir( $dir, 0755, true );
		}
	}
	if ( ! is_dir( $dir ) ) {
		return false;
	}

	$htaccess = $dir . '/.htaccess';
	if ( ! is_file( $htaccess ) ) {
		$deny  = "# FatalGuard — deny all web access\n";
		$deny .= "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n";
		$deny .= "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n";
		@file_put_contents( $htaccess, $deny, LOCK_EX );
	}
	$index = $dir . '/index.php';
	if ( ! is_file( $index ) ) {
		@file_put_contents( $index, "<?php\nhttp_response_code( 403 );\nexit;\n", LOCK_EX );
	}
	return true;
}

/**
 * Seed the alert-dispatch offset once, at install/activation time, to the current
 * end of the fatals log. Without this the marker is created lazily on the FIRST
 * init-sweep — and that first sweep resets to EOF and returns without dispatching,
 * so any fatal captured between activation and that first sweep is silently
 * swallowed (never reaches Slack/Discord/webhook/email). Seeding here makes the
 * baseline "everything up to activation is history"; every fatal AFTER activation
 * is new and dispatches. Idempotent — only writes when the marker is missing, so a
 * real offset is never rewound.
 */
function fatalguard_seed_dispatch_marker(): void {
	if ( ! function_exists( 'fatalguard_dir' ) ) {
		return;
	}
	$marker = fatalguard_dir() . '/.dispatched';
	if ( is_file( $marker ) ) {
		return;
	}
	$size = 0;
	if ( function_exists( 'fatalguard_fatals_log' ) ) {
		$path = fatalguard_fatals_log();
		if ( '' !== $path && @is_file( $path ) ) {
			$size = (int) @filesize( $path );
		}
	}
	@file_put_contents( $marker, (string) $size, LOCK_EX );
}

/** Whether an on-disk drop-in is present and is OURS. */
function fatalguard_dropin_is_ours(): bool {
	$path = fatalguard_dropin_path();
	if ( ! is_file( $path ) ) {
		return false;
	}
	// 8KB window: the marker sits in the template's opening docblock (first ~300
	// bytes), but pre-marker-comment builds only carried it at the class declaration
	// (~6.5KB in) — keep the window past that so upgrades from them still heal.
	$head = @file_get_contents( $path, false, null, 0, 8192 );
	return is_string( $head ) && false !== strpos( $head, fatalguard_dropin_marker() );
}

/**
 * True when a foreign (non-fatalguard) fatal-error-handler.php is in place — we must
 * not overwrite it. Surfaced by the Status tab.
 */
function fatalguard_foreign_dropin_present(): bool {
	$path = fatalguard_dropin_path();
	if ( ! is_file( $path ) ) {
		return false;
	}
	return ! fatalguard_dropin_is_ours();
}

/** Remove our drop-in (used by the kill switch / uninstall). */
function fatalguard_remove_dropin(): void {
	if ( fatalguard_dropin_is_ours() ) {
		@unlink( fatalguard_dropin_path() );
		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( fatalguard_dropin_path(), true );
		}
	}
}

/** Delete the stale floating-overlay orphan mu-plugin from the old tool. */
function fatalguard_cleanup_orphan(): void {
	// B090/B182: WPMU_PLUGIN_DIR honours a relocated mu-plugins directory and is
	// always defined here (this runs in booted WordPress, after wp_plugin_directory_constants()).
	$mu_base = WPMU_PLUGIN_DIR;
	$orphan  = $mu_base . '/fatalguard-debug-overlay.php';
	if ( '' === $orphan || ! is_file( $orphan ) ) {
		return;
	}
	$head = @file_get_contents( $orphan, false, null, 0, 4096 );
	if ( is_string( $head ) && ( false !== strpos( $head, 'FatalGuard_Debug_Overlay' ) || false !== strpos( $head, 'fatalguard_debug_overlay' ) ) ) {
		@unlink( $orphan );
	}
}

/**
 * The entry point: ensure the log dir, heal/remove the drop-in, clear the
 * orphan. Cheap and idempotent; safe to call on every request.
 */
function fatalguard_self_install(): void {
	fatalguard_cleanup_orphan();

	// Kill switch: remove our boot artifact and stop.
	if ( fatalguard_is_disabled() ) {
		fatalguard_remove_dropin();
		return;
	}

	// B047: capture return value so a root-owned / unwritable log dir is detected.
	$logdir_ok = fatalguard_ensure_logdir();
	if ( ! $logdir_ok ) {
		// Surface a persistent admin notice so the operator knows capture is broken.
		add_action( 'admin_notices', 'fatalguard_notice_logdir_unwritable' );
		return;
	}
	fatalguard_seed_dispatch_marker();

	// Never clobber a third-party drop-in. Surface a notice (B101).
	if ( fatalguard_foreign_dropin_present() ) {
		add_action( 'admin_notices', 'fatalguard_notice_foreign_dropin' );
		return;
	}

	$expected = fatalguard_render_dropin();
	if ( '' === $expected ) {
		return; // template unreadable — do nothing rather than write garbage
	}

	$path = fatalguard_dropin_path();

	// B219: fast-path — skip the full file_get_contents() read when the on-disk drop-in is
	// current. The stored value is a signature of every baked input (path/flag/version), so a
	// change to any of them (upload path drift, version bump) no longer
	// matches and we fall through to a full compare + heal — the disk copy can never silently
	// disagree with the live plugin state.
	$sig = fatalguard_dropin_signature();
	if ( get_option( 'fatalguard_dropin_ok' ) === $sig ) {
		// B125: also verify the version constant of the loaded (possibly stale opcache) drop-in
		// is correct before trusting the disk-ok flag.
		if ( defined( 'FATALGUARD_DROPIN_VERSION' ) && FATALGUARD_DROPIN_VERSION === '1.2.1' ) {
			return;
		}
		// Signature matched but the loaded drop-in is a stale opcache pool — fall through to heal.
	}

	$current = is_file( $path ) ? (string) @file_get_contents( $path ) : '';
	if ( $current !== $expected ) {
		// B061: capture write_atomic result; persist failure so Status can surface it.
		$ok = fatalguard_write_atomic( $path, $expected );
		if ( ! $ok ) {
			set_transient( 'fatalguard_dropin_stale', 1, DAY_IN_SECONDS );
			add_action( 'admin_notices', 'fatalguard_notice_write_failed' );
			return;
		}
		// B219: successful heal — store the current input signature for the fast-path.
		delete_transient( 'fatalguard_dropin_stale' );
		update_option( 'fatalguard_dropin_ok', $sig, true );
	} else {
		// Disk matches — store the current input signature for the fast-path (B219).
		if ( get_option( 'fatalguard_dropin_ok' ) !== $sig ) {
			update_option( 'fatalguard_dropin_ok', $sig, true );
		}
	}
}

// ---------------------------------------------------------------------------
// Admin notices surfaced by self_install() (B047 / B061 / B101).
// ---------------------------------------------------------------------------

/** Notice: log directory could not be created or is not writable (B047). */
function fatalguard_notice_logdir_unwritable(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	echo '<div class="notice notice-error is-dismissible"><p><strong>FatalGuard:</strong> '
		. esc_html__( 'The log directory could not be created or is not writable by the web server. Fatal errors will not be captured. Check directory ownership and permissions.', 'fatalguard' )
		. '</p></div>';
}

/** Notice: a foreign fatal-error-handler.php is already in place (B101). */
function fatalguard_notice_foreign_dropin(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	echo '<div class="notice notice-error is-dismissible"><p><strong>FatalGuard:</strong> '
		. esc_html__( 'Another plugin or the host has installed wp-content/fatal-error-handler.php. FatalGuard cannot install its capture handler without overwriting it. Fatal errors will not be captured.', 'fatalguard' )
		. '</p></div>';
}

/** Notice: drop-in write failed (wp-content not writable) (B061). */
function fatalguard_notice_write_failed(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	echo '<div class="notice notice-error is-dismissible"><p><strong>FatalGuard:</strong> '
		. esc_html__( 'Could not write wp-content/fatal-error-handler.php (directory not writable). The installed drop-in may be stale. Fatal errors might not be captured correctly.', 'fatalguard' )
		. '</p></div>';
}
