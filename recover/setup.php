<?php
/**
 * FatalGuard recovery — installs the tiny mu-plugin loader that boots the recovery
 * engine BEFORE regular plugins (so a plugin that fatals on load can still be
 * switched off). The loader references WP_PLUGIN_DIR (defined before mu-plugins
 * load) + the engine's relative path, so it survives the site moving hosts.
 *
 * @package FatalGuard
 */

defined( 'ABSPATH' ) || exit;

/** The mu-plugins directory (WPMU_PLUGIN_DIR is always defined in booted WordPress). */
function fatalguard_recover_mu_dir(): string {
	return WPMU_PLUGIN_DIR;
}

/** The loader file's contents (points at the engine by its plugins-relative path). */
function fatalguard_recover_loader_contents(): string {
	$engine = plugin_dir_path( FATALGUARD_FILE ) . 'recover/recover.php';
	$rel    = plugin_basename( $engine ); // e.g. fatalguard/recover/recover.php
	// B083: guard on FatalGuard still being in active_plugins so an orphaned loader
	// (failed unlink after deactivation) is inert and doesn't run the engine needlessly.
	// Bake the ACTUAL main-file basename — the premium bundle installs as
	// fatalguard-premium/fatalguard.php, so a hardcoded 'fatalguard/...' would make
	// the guard always-false on Pro and never load the recovery engine for paying users.
	$main = plugin_basename( FATALGUARD_FILE ); // fatalguard/fatalguard.php OR fatalguard-premium/fatalguard.php
	return "<?php\n"
		. "// FatalGuard recovery loader — auto-generated, safe to delete (FatalGuard rewrites it).\n"
		. "// Loads the recovery engine before regular plugins so a fatal plugin can still be switched off.\n"
		. "defined( 'ABSPATH' ) || exit;\n"
		. "\$_fg_active = isset( \$GLOBALS['_fg_loader_active'] ) ? \$GLOBALS['_fg_loader_active'] : ( defined( 'WP_PLUGIN_DIR' ) && function_exists( 'get_option' ) && in_array( '" . $main . "', (array) get_option( 'active_plugins', [] ), true ) );\n"
		. "if ( \$_fg_active ) {\n"
		. "\t\$fatalguard_recover = WP_PLUGIN_DIR . '/" . $rel . "';\n"
		. "\tif ( is_readable( \$fatalguard_recover ) ) { require_once \$fatalguard_recover; }\n"
		. "}\n";
}

/**
 * Atomic write helper for the mu-plugin loader.
 * Writes to a temp file then renames so there is never a truncate window —
 * consistent with fatalguard_write_atomic() in the self-installer.
 *
 * @param string $target  Absolute path to the target file.
 * @param string $content Content to write.
 * @return bool           True on success, false on failure.
 */
function fatalguard_recover_write_atomic( string $target, string $content ): bool {
	$tmp = $target . '.tmp.' . getmypid();
	$ok  = @file_put_contents( $tmp, $content );
	if ( false === $ok || (int) $ok !== strlen( $content ) ) {
		wp_delete_file( $tmp );
		return false;
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic temp+rename so a partial write never leaves a broken mu-plugin; WP_Filesystem::move() is not guaranteed atomic.
	return (bool) @rename( $tmp, $target );
}

/** Write the loader if missing or out of date (self-heals; only writes on drift). */
function fatalguard_recover_install_loader(): void {
	// admin_init fires for ANY logged-in user (a subscriber loading their profile) —
	// filesystem writes stay admin-only. Activation hooks already run as an admin.
	if ( function_exists( 'current_user_can' ) && ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	$dir = fatalguard_recover_mu_dir();
	if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
		return;
	}
	$target  = $dir . '/fatalguard-recover.php';
	$content = fatalguard_recover_loader_contents();
	if ( ! is_file( $target ) || (string) @file_get_contents( $target ) !== $content ) {
		// B120: use atomic write (temp + rename) so a partial write never leaves a
		// broken mu-plugin file that would fatal every request.
		fatalguard_recover_write_atomic( $target, $content );
	}
}

/** Remove the loader — recovery is a FatalGuard-only feature. */
function fatalguard_recover_uninstall_loader(): void {
	$target = fatalguard_recover_mu_dir() . '/fatalguard-recover.php';
	if ( is_file( $target ) ) {
		// B083: surface an admin notice on failure so the site owner knows the orphaned
		// loader persists and needs manual removal. wp_delete_file() returns void, so
		// re-derive success from the file no longer existing.
		wp_delete_file( $target );
		$ok = ! is_file( $target );
		if ( ! $ok && function_exists( 'add_action' ) ) {
			add_action(
				'admin_notices',
				static function () use ( $target ): void {
					if ( ! current_user_can( 'activate_plugins' ) ) {
						return;
					}
					echo '<div class="notice notice-warning is-dismissible"><p>'
						. '<strong>FatalGuard:</strong> Could not remove the recovery mu-plugin loader at '
						. esc_html( $target ) . '. Please delete it manually — until removed, the recovery engine '
						. 'will continue to load on every request.</p></div>';
				}
			);
		}
	}
}

register_activation_hook( FATALGUARD_FILE, 'fatalguard_recover_install_loader' );
register_deactivation_hook( FATALGUARD_FILE, 'fatalguard_recover_uninstall_loader' );
// Self-heal on admin load — cheap (writes only when the file is missing/changed).
add_action( 'admin_init', 'fatalguard_recover_install_loader' );
