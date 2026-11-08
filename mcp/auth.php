<?php
/**
 * MCP — API key mint / store / verify (free + Pro).
 *
 * The key is the ONLY credential an AI client presents (Bearer). It is read-only
 * by construction: it unlocks the MCP endpoint, and the endpoint exposes only
 * read tools. We store just the SHA-256 hash — in a DEDICATED option, NOT in
 * `fatalguard_settings`: that option is registered via register_setting, so its
 * `sanitize_option_*` filter fires on EVERY update_option and rebuilds the value
 * from known form fields, which would silently strip a programmatic key on save
 * (and re-add it on revoke). A standalone option sidesteps that entirely. The
 * plaintext is shown once at generation (stashed in a short transient), never
 * persisted.
 *
 * @package FatalGuard
 */

defined( 'ABSPATH' ) || exit;

const FATALGUARD_MCP_KEY_PREFIX  = 'fg_';                        // human-recognisable, greppable in logs
const FATALGUARD_MCP_KEY_OPTION  = 'fatalguard_mcp_key_hash';    // standalone option (NOT the form-sanitized settings)
const FATALGUARD_MCP_SHOW_ONCE   = 'fatalguard_mcp_key_plain';   // transient holding the just-minted key
const FATALGUARD_MCP_SHOW_TTL    = 300;                          // show-once window (seconds)

/** True once an MCP key has been generated for this site. */
function fatalguard_mcp_has_key(): bool {
	return '' !== (string) get_option( FATALGUARD_MCP_KEY_OPTION, '' );
}

/**
 * Mint a fresh key: random 20 bytes → `fg_<40 hex>`. Stores only its hash,
 * revoking any previous key. Returns the plaintext (caller shows it once).
 */
function fatalguard_mcp_generate_key(): string {
	$key = FATALGUARD_MCP_KEY_PREFIX . bin2hex( random_bytes( 20 ) );
	update_option( FATALGUARD_MCP_KEY_OPTION, hash( 'sha256', $key ), false );

	// Show-once: stash the plaintext briefly so the settings screen can render it
	// right after the redirect, then it's gone for good.
	set_transient( FATALGUARD_MCP_SHOW_ONCE, $key, FATALGUARD_MCP_SHOW_TTL );

	return $key;
}

/** Revoke the current key (endpoint immediately refuses every client). */
function fatalguard_mcp_revoke_key(): void {
	delete_option( FATALGUARD_MCP_KEY_OPTION );
	delete_transient( FATALGUARD_MCP_SHOW_ONCE );
}

/**
 * Read the presented key from the request. Prefers a custom header
 * (X-FatalGuard-Key → HTTP_X_FATALGUARD_KEY) because the standard Authorization
 * header is silently stripped before PHP on many Apache CGI/FastCGI shared hosts
 * (the same footgun WordPress warns about for Application Passwords). Falls back
 * to `Authorization: Bearer <key>` for clients that only speak that. '' if none.
 */
function fatalguard_mcp_bearer( WP_REST_Request $request ): string {
	$custom = trim( (string) $request->get_header( 'x_fatalguard_key' ) );
	if ( '' !== $custom ) {
		return $custom;
	}
	$header = (string) ( $request->get_header( 'authorization' ) ?: $request->get_header( 'Authorization' ) );
	if ( 0 === stripos( $header, 'Bearer ' ) ) {
		return trim( substr( $header, 7 ) );
	}
	return '';
}

/**
 * Constant-time verify of a presented key against the stored hash. False if no
 * key is set, the header is missing, or it doesn't match.
 */
function fatalguard_mcp_verify( WP_REST_Request $request ): bool {
	$hash = (string) get_option( FATALGUARD_MCP_KEY_OPTION, '' );
	if ( '' === $hash ) {
		return false;
	}
	$presented = fatalguard_mcp_bearer( $request );
	if ( '' === $presented ) {
		return false;
	}
	return hash_equals( $hash, hash( 'sha256', $presented ) );
}
