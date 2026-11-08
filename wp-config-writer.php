<?php
/**
 * wp-config debug-constant sync — the ONLY mechanism the plugin uses to route
 * PHP error logging/display. On an explicit settings toggle it writes
 * WP_DEBUG / WP_DEBUG_LOG / WP_DEBUG_DISPLAY into wp-config.php (backing up
 * whatever was there) and WordPress core applies them in wp_debug_mode().
 * The plugin itself calls neither ini_set() nor error_reporting(), ever.
 *
 * Same approach as the review-approved Debug Log Manager; restore-on-disable
 * puts back the site's original constants byte-for-byte.
 *
 * @package FatalGuard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/wp-config-transformer.php';

const FATALGUARD_DL_WPCONFIG_BACKUP = 'fatalguard_dl_wpconfig_backup';

/** The three constants this module manages. */
function fatalguard_wpconfig_constants(): array {
	return array( 'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY' );
}

/** Shared transformer instance. */
function fatalguard_wpconfig(): FatalGuard_DL_Config_Transformer {
	static $t = null;
	if ( null === $t ) {
		$t = new FatalGuard_DL_Config_Transformer();
	}
	return $t;
}

/** Whether wp-config.php can be managed (found + writable). */
function fatalguard_wpconfig_writable(): bool {
	return fatalguard_wpconfig()->writable();
}

/**
 * Snapshot a constant's original state into the backup option — once. The first
 * modification wins; later syncs never overwrite the true original.
 */
function fatalguard_wpconfig_backup_constant( string $name ): void {
	$backup = get_option( FATALGUARD_DL_WPCONFIG_BACKUP, array() );
	$backup = is_array( $backup ) ? $backup : array();
	if ( array_key_exists( $name, $backup ) ) {
		return;
	}
	$t               = fatalguard_wpconfig();
	$value           = $t->get_value( $name );
	$backup[ $name ] = array(
		'existed' => null !== $value,
		'value'   => (string) $value,
	);
	update_option( FATALGUARD_DL_WPCONFIG_BACKUP, $backup, false );
}

/**
 * Sync the managed constants to the desired feature state.
 *
 * @param bool $log     Route PHP errors to the protected log file.
 * @param bool $display Show PHP errors on screen (dev toggle).
 * @return true|string  True on success, error message string on failure.
 */
function fatalguard_wpconfig_sync( bool $log, bool $display ) {
	$t = fatalguard_wpconfig();
	if ( '' === $t->path() ) {
		return __( 'wp-config.php could not be located.', 'fatalguard' );
	}
	if ( ! $t->writable() ) {
		return __( 'wp-config.php is not writable.', 'fatalguard' );
	}

	try {
		if ( ! $log && ! $display ) {
			// Both features off: restore the site's original constants and clear the backup.
			$backup = get_option( FATALGUARD_DL_WPCONFIG_BACKUP, array() );
			$backup = is_array( $backup ) ? $backup : array();
			foreach ( fatalguard_wpconfig_constants() as $name ) {
				if ( ! array_key_exists( $name, $backup ) ) {
					continue; // Never touched by us.
				}
				if ( ! empty( $backup[ $name ]['existed'] ) ) {
					$t->update( $name, (string) $backup[ $name ]['value'] );
				} else {
					$t->remove( $name );
				}
			}
			delete_option( FATALGUARD_DL_WPCONFIG_BACKUP );
			return true;
		}

		foreach ( fatalguard_wpconfig_constants() as $name ) {
			fatalguard_wpconfig_backup_constant( $name );
		}

		// Either feature needs WP_DEBUG (core gates both error_reporting and
		// display handling on it in wp_debug_mode()).
		$t->update( 'WP_DEBUG', 'true' );
		if ( $log ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Builds the PHP source literal written into wp-config.php (a quoted path), not debug output.
			$t->update( 'WP_DEBUG_LOG', var_export( fatalguard_php_log(), true ) );
		} else {
			$t->remove( 'WP_DEBUG_LOG' );
		}
		// Explicit either way: display on is the user's dev choice; display off
		// prevents WP_DEBUG's default from spraying notices onto production pages.
		$t->update( 'WP_DEBUG_DISPLAY', $display ? 'true' : 'false' );
		return true;
	} catch ( \Throwable $e ) {
		return $e->getMessage();
	}
}

/**
 * The copy-paste snippet offered when wp-config.php is not writable
 * (the Error Log Monitor fallback pattern).
 */
function fatalguard_wpconfig_snippet( bool $log, bool $display ): string {
	$lines = array( "define( 'WP_DEBUG', true );" );
	if ( $log ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Builds the PHP source literal shown to the user for wp-config.php, not debug output.
		$lines[] = "define( 'WP_DEBUG_LOG', " . var_export( fatalguard_php_log(), true ) . ' );';
	}
	$lines[] = "define( 'WP_DEBUG_DISPLAY', " . ( $display ? 'true' : 'false' ) . ' );';
	return implode( "\n", $lines );
}
