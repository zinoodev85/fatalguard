<?php
/**
 * FatalGuard uninstall cleanup — registered on Freemius' `after_uninstall` hook
 * (Freemius intercepts WordPress' native uninstall, so a root uninstall.php is
 * not allowed alongside the SDK). Removes all stored data AND the executing
 * artifacts (drop-in, recovery mu-loader, private log dir). Self-contained:
 * recomputes every path locally so it works regardless of load order.
 *
 * @package FatalGuard
 */

defined( 'ABSPATH' ) || exit;

/** Recursively delete a directory — refuses anything that isn't our log dir. */
function fatalguard_uninstall_rmdir( string $dir ): void {
	if ( '' === $dir || false === strpos( $dir, '/fatalguard-debug-' ) || ! is_dir( $dir ) ) {
		return; // hard guard: only ever our suffixed private log dir
	}
	$items = @scandir( $dir );
	if ( false === $items ) {
		return;
	}
	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $dir . '/' . $item;
		if ( is_dir( $path ) ) {
			fatalguard_uninstall_rmdir( $path );
		} else {
			wp_delete_file( $path );
		}
	}
	@rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing our own now-empty private dir at uninstall; WP_Filesystem is heavyweight for a single rmdir here.
}

/** Full data + artifact removal. Runs on Freemius after_uninstall. */
function fatalguard_uninstall_cleanup(): void {
	// Guard: if both sibling bundles are still on disk (e.g. free deactivated while
	// premium is installed-but-deactivated), bail — shared data belongs to the
	// sibling and must not be destroyed (B040). The plugins root is derived from
	// THIS file's location (wp.org: no hardcoded WP_PLUGIN_DIR assumptions).
	$plugins_root = dirname( plugin_dir_path( __FILE__ ) );
	if (
		is_dir( $plugins_root . '/fatalguard' ) &&
		is_dir( $plugins_root . '/fatalguard-premium' )
	) {
		return;
	}

	// 0. Restore any WP_DEBUG_* constants we wrote to wp-config.php (the sync
	// with both features off puts back the site's originals from the backup and
	// deletes the backup option; a no-op when we never touched wp-config).
	// If the restore FAILS (wp-config read-only at uninstall time), the backup
	// option must survive — it is the only copy of the site's original values,
	// and deleting it would freeze our WP_DEBUG_* lines in wp-config forever.
	$wpconfig_restored = true;
	if ( function_exists( 'fatalguard_wpconfig_sync' ) ) {
		$wpconfig_restored = ( true === fatalguard_wpconfig_sync( false, false ) );
	}

	// 1. Options.
	delete_option( 'fatalguard_settings' );
	delete_option( 'fatalguard_options' );
	if ( $wpconfig_restored ) {
		delete_option( 'fatalguard_dl_wpconfig_backup' ); // wp-config originals (normally gone via the restore above)
	}
	delete_option( 'fatalguard_wpconfig_error' );
	delete_option( 'fatalguard_dropin_ok' );        // drop-in fast-path signature (autoloaded)
	delete_option( 'fatalguard_history_db_version' );
	delete_option( 'fatalguard_mcp_key_hash' );
	delete_option( 'fatalguard_delivery_failures' );  // B103/B143/B166/B169/B175/B187
	delete_option( 'fatalguard_delivery_failures_dismissed' ); // wp.org G11: persisted X-dismissal
	delete_option( 'fatalguard_activated_at' );        // B103/B166/B169/B175/B187
	delete_option( 'fatalguard_review_dismissed' );    // B103/B166/B169/B175/B187
	delete_option( 'fatalguard_dl_health_last_run' );   // Telegram healthcheck cursor
	delete_option( 'fatalguard_dl_tg_network_fails' );  // Telegram failure counter

	global $wpdb;

	// 1b. Pro error-history table (if this install was ever Pro). Fixed name, no interpolation.
	if ( isset( $wpdb ) ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}fatalguard_history`" ); // phpcs:ignore WordPress.DB
	}
	if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
		wp_clear_scheduled_hook( 'fatalguard_pro_history_prune' );
		wp_clear_scheduled_hook( 'fatalguard_weekly_digest' );
		wp_clear_scheduled_hook( 'fatalguard_telegram_healthcheck' );
	}

	// 2. Transients (recovery single-use markers, etc.) — prepared LIKE. Also the
	// DB-backed used-token rows (fatalguard_recover_used_<sig>): deliberately created
	// as options because they must outlive the object cache, so the transient sweep
	// never catches them — one row exists per recovery link ever used.
	if ( isset( $wpdb ) ) {
		$like  = $wpdb->esc_like( '_transient_fatalguard_' ) . '%';
		$like2 = $wpdb->esc_like( '_transient_timeout_fatalguard_' ) . '%';
		$like3 = $wpdb->esc_like( 'fatalguard_recover_used_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot bulk sweep at uninstall; no core API for LIKE-delete and caching is irrelevant on teardown.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				$like,
				$like2,
				$like3
			)
		);
	}

	// Uninstall always runs in a fully-booted WordPress, so WP_CONTENT_DIR is defined.
	$content_dir = rtrim( WP_CONTENT_DIR, '/\\' );

	// 3. The fatal-error drop-in — only if it is OURS (never touch a foreign one).
	$dropin = $content_dir . '/fatal-error-handler.php';
	if ( is_file( $dropin ) ) {
		$head = (string) @file_get_contents( $dropin, false, null, 0, 4096 );
		if ( false !== strpos( $head, 'FatalGuard_DL_Dropin_Handler' ) ) {
			wp_delete_file( $dropin );
		}
	}

	// 4. The recovery mu-loader — only if it is ours. WPMU_PLUGIN_DIR is always
	// defined in a booted WordPress (wp_plugin_directory_constants()).
	$mu_dir = WPMU_PLUGIN_DIR;
	$loader = rtrim( $mu_dir, '/\\' ) . '/fatalguard-recover.php';
	if ( is_file( $loader ) ) {
		$head = (string) @file_get_contents( $loader, false, null, 0, 4096 );
		if ( false !== strpos( $head, 'FatalGuard recovery loader' ) ) {
			wp_delete_file( $loader );
		}
	}

	// 5. The private log dirs (fatalguard-debug-<12-hex suffix>). Swept by glob instead
	//    of recomputing the hash: the prefix is ours alone, and a glob also catches dirs
	//    whose suffix was derived by an older seed formula (pre-1.2.4) or a since-changed
	//    AUTH_KEY. Current location is uploads/; pre-1.2.3 dirs sat at the wp-content root.
	$uploads  = wp_upload_dir( null, false );
	$log_base = ( is_array( $uploads ) && ! empty( $uploads['basedir'] ) ) ? (string) $uploads['basedir'] : '';
	foreach ( array( rtrim( $log_base, '/\\' ), $content_dir ) as $base ) {
		if ( '' === $base ) {
			continue;
		}
		$dirs = glob( $base . '/fatalguard-debug-*', GLOB_ONLYDIR );
		foreach ( is_array( $dirs ) ? $dirs : array() as $dir ) {
			if ( preg_match( '/\/fatalguard-debug-[0-9a-f]{12}$/', str_replace( '\\', '/', $dir ) ) ) {
				fatalguard_uninstall_rmdir( $dir );
			}
		}
	}

	// 6. Legacy v1.0.0 JS-error log at wp-content root (B115): the M2 security
	//    patch moved new writes into the private log dir, but stranded any existing
	//    file. Remove it on uninstall (and on plugin load via activation hook).
	$legacy_js_log = $content_dir . '/fatalguard-js.log';
	if ( is_file( $legacy_js_log ) ) {
		wp_delete_file( $legacy_js_log );
	}
}
