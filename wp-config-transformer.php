<?php
/**
 * Vendored wp-config.php transformer (constants read/write with backup-friendly
 * raw values). Derived from wp-cli/wp-config-transformer via Debug Log Manager's
 * GPL bundle; class renamed for prefix safety, HTML output and dead branches
 * removed, missing-anchor fallback fixed (EOF instead of undefined variable).
 *
 * Only used from wp-admin on an explicit user action (settings toggle) — never
 * at runtime/request time. This is the review-proven mechanism for a plugin to
 * route PHP error logging: write WP_DEBUG / WP_DEBUG_LOG / WP_DEBUG_DISPLAY
 * constants and let WordPress core apply them in wp_debug_mode().
 *
 * @package FatalGuard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'FatalGuard_DL_Config_Transformer' ) ) {
	return;
}

/**
 * Reads and edits constants in wp-config.php.
 */
class FatalGuard_DL_Config_Transformer {

	/** @var string Normalized wp-config.php source of the last read. */
	private $wp_config_src = '';

	/** @var array Parsed configs of the last read. */
	private $wp_configs = array();

	/**
	 * Locate wp-config.php exactly like wp-load.php does. The file's location is
	 * defined by core relative to the install root — there is no WordPress API
	 * for it, so this is the sole place the root constant is read (same logic as
	 * core and the approved Debug Log Manager).
	 *
	 * @return string Absolute path, or '' when undetectable.
	 */
	public function path(): string {
		$root = rtrim( (string) ABSPATH, '/\\' ) . '/'; // sole use; the file guard above guarantees it's defined
		if ( file_exists( $root . 'wp-config.php' ) ) {
			return $root . 'wp-config.php';
		}
		$parent = dirname( $root );
		if ( @file_exists( $parent . '/wp-config.php' ) && ! @file_exists( $parent . '/wp-settings.php' ) ) {
			return $parent . '/wp-config.php';
		}
		return '';
	}

	/** Whether wp-config.php was found and is writable. */
	public function writable(): bool {
		$file = $this->path();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Capability probe for the settings UI; WP_Filesystem may prompt for FTP credentials mid-toggle, which is unusable here. Same approach as the approved Debug Log Manager.
		return '' !== $file && is_writable( $file );
	}

	/** Parse all constants/variables out of wp-config.php. */
	private function configs(): array {
		$file = $this->path();
		$src  = '' !== $file ? (string) file_get_contents( $file ) : '';
		// Normalize newlines (OSX/CRLF) so anchors and regexes behave.
		$src                 = str_replace( array( "\n\r", "\r" ), "\n", $src );
		$this->wp_config_src = $src;

		$configs = array(
			'constant' => array(),
			'variable' => array(),
		);
		if ( '' === trim( $src ) ) {
			$this->wp_configs = $configs;
			return $configs;
		}

		// Strip comments before matching.
		$stripped = $src;
		foreach ( token_get_all( $stripped ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$stripped = str_replace( $token[1], '', $stripped );
			}
		}

		preg_match_all( '/(?<=^|;|<\?php\s|<\?\s)(\h*define\s*\(\s*[\'"](\w*?)[\'"]\s*)(,\s*(\'\'|""|\'.*?[^\\\\]\'|".*?[^\\\\]"|.*?)\s*)((?:,\s*(?:true|false)\s*)?\)\s*;)/ims', $stripped, $constants );

		if ( ! empty( $constants[2] ) ) {
			foreach ( $constants[2] as $index => $name ) {
				$configs['constant'][ $name ] = array(
					'src'   => $constants[0][ $index ],
					'value' => $constants[4][ $index ],
					'parts' => array(
						$constants[1][ $index ],
						$constants[3][ $index ],
						$constants[5][ $index ],
					),
				);
			}
		}

		$this->wp_configs = $configs;
		return $configs;
	}

	/** Whether a constant is defined in wp-config.php. */
	public function exists( string $name ): bool {
		$configs = $this->configs();
		return isset( $configs['constant'][ $name ] );
	}

	/**
	 * Raw source value of a constant (e.g. "true", "'/path/x.log'").
	 *
	 * @return string|null Null when the constant is not present.
	 */
	public function get_value( string $name ): ?string {
		$configs = $this->configs();
		return isset( $configs['constant'][ $name ] ) ? (string) $configs['constant'][ $name ]['value'] : null;
	}

	/**
	 * Add or update a constant. $value is the RAW source representation:
	 * pass "true"/"false" for booleans, or a var_export()'d string for paths.
	 *
	 * @return bool True when the file changed.
	 */
	public function update( string $name, string $value ): bool {
		if ( ! $this->exists( $name ) ) {
			return $this->add( $name, $value );
		}

		$old       = $this->wp_configs['constant'][ $name ];
		$new_parts = $old['parts'];
		// Only edit the value part, preserving the author's spacing/quoting style.
		$new_parts[1] = str_replace( $old['value'], $value, $new_parts[1] );
		$new_src      = implode( '', $new_parts );

		$contents = preg_replace(
			sprintf( '/(?<=^|;|<\?php\s|<\?\s)(\s*?)%s/m', preg_quote( trim( $old['src'] ), '/' ) ),
			'$1' . str_replace( '$', '\$', trim( $new_src ) ),
			$this->wp_config_src,
			1
		);
		return $this->save( $contents );
	}

	/** Insert a new constant before core's "stop editing" anchor (EOF fallback). */
	private function add( string $name, string $value ): bool {
		$src = $this->wp_config_src;
		$new = sprintf( "define( '%s', %s );", $name, $value );

		$anchor = '';
		if ( false !== strpos( $src, 'Happy publishing' ) ) {
			$anchor = "/* That's all, stop editing! Happy publishing. */";
		} elseif ( false !== strpos( $src, 'Happy blogging' ) ) {
			$anchor = "/* That's all, stop editing! Happy blogging. */";
		}

		if ( '' !== $anchor && false !== strpos( $src, $anchor ) ) {
			$contents = str_replace( $anchor, $new . PHP_EOL . $anchor, $src );
		} else {
			$contents = rtrim( $src ) . PHP_EOL . $new . PHP_EOL;
		}
		return $this->save( $contents );
	}

	/** Remove a constant definition entirely. */
	public function remove( string $name ): bool {
		if ( ! $this->exists( $name ) ) {
			return false;
		}
		$pattern  = sprintf( '/(?<=^|;|<\?php\s|<\?\s)\h*%s\s*?(\n|$)/m', preg_quote( trim( $this->wp_configs['constant'][ $name ]['src'] ), '/' ) );
		$contents = preg_replace( $pattern, '$1', $this->wp_config_src, 1 );
		return $this->save( $contents );
	}

	/** Persist new contents; refuses empty output (never truncate wp-config). */
	private function save( ?string $contents ): bool {
		if ( null === $contents || '' === trim( $contents ) ) {
			return false;
		}
		if ( $contents === $this->wp_config_src ) {
			return false;
		}
		$file = $this->path();
		if ( '' === $file ) {
			return false;
		}
		// Never write a config that would ParseError — that bricks the whole site.
		try {
			token_get_all( $contents, TOKEN_PARSE );
		} catch ( \ParseError $e ) {
			return false;
		}
		$result = file_put_contents( $file, $contents, LOCK_EX );
		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $file, true );
		}
		return false !== $result;
	}
}
