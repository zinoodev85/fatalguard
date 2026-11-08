<?php
/**
 * MCP — settings panel + key management (free + Pro).
 *
 * Renders the "AI access (MCP)" section in the settings screen's AI tab
 * (settings-page.php calls fatalguard_mcp_render_section()). Key generate/revoke
 * go through admin-post.php with a nonce — no extra REST surface. Styles and the
 * Copy buttons live in assets/settings.css + assets/settings-ui.js (enqueued, no
 * inline <style>/<script>). The plaintext key is shown exactly once.
 *
 * @package FatalGuard
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_post_fatalguard_mcp_key', 'fatalguard_mcp_handle_key_action' );

/** The endpoint an AI client connects to. */
function fatalguard_mcp_endpoint_url(): string {
	return esc_url_raw( rest_url( 'fatalguard/v1/mcp' ) );
}

/** Render the AI / MCP settings card. */
function fatalguard_mcp_render_section( string $opt_name ): void {
	$url      = fatalguard_mcp_endpoint_url();
	$has_key  = fatalguard_mcp_has_key();
	$just_key = get_transient( FATALGUARD_MCP_SHOW_ONCE );
	if ( $just_key ) {
		delete_transient( FATALGUARD_MCP_SHOW_ONCE ); // show exactly once
	}

	echo '<section class="fg-sec">';
	echo '<div class="fg-sec__head"><span class="fg-sec__label">' . esc_html__( 'AI access (MCP)', 'fatalguard' ) . '</span>';
	echo '<span class="fg-sec__hint">' . esc_html__( 'Free — let an AI assistant read your errors', 'fatalguard' ) . '</span></div>';
	echo '<div class="fg-card">';

	echo '<p class="fg-sub">' . esc_html__( 'Connect Claude, Cursor or any MCP client and ask it what is crashing your site. Read-only. Works only while WordPress can still load.', 'fatalguard' ) . '</p>';

	// --- Key: big generate on top; the just-minted key shown separately ----
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="fg-mcp-keybar">';
	echo '<input type="hidden" name="action" value="fatalguard_mcp_key" />';
	wp_nonce_field( 'fatalguard_mcp_key' );

	if ( $just_key ) {
		// The plaintext key, on its own, copyable — shown exactly once.
		echo '<div class="fg-mcp-keybox">';
		echo '<label class="fg-mcp-keylabel">' . esc_html__( 'Your API key — copy it now, it is shown only once', 'fatalguard' ) . '</label>';
		echo '<div class="fg-mcp-keyrow">';
		printf( '<input type="text" class="fg-mcp-key" readonly value="%s" />', esc_attr( $just_key ) );
		echo '<button type="button" class="button fg-copy-btn" data-fg-copy>' . esc_html__( 'Copy', 'fatalguard' ) . '</button>';
		echo '</div></div>';
		echo '<div class="fg-mcp-actions">';
		echo '<button type="submit" name="op" value="generate" class="button button-secondary">' . esc_html__( 'Regenerate key', 'fatalguard' ) . '</button> ';
		echo '<button type="submit" name="op" value="revoke" class="button button-secondary">' . esc_html__( 'Revoke key', 'fatalguard' ) . '</button>';
		echo '</div>';
	} elseif ( $has_key ) {
		echo '<button type="submit" name="op" value="generate" class="button button-primary button-hero fg-mcp-gen">' . esc_html__( 'Regenerate key', 'fatalguard' ) . '</button> ';
		echo '<button type="submit" name="op" value="revoke" class="button button-secondary fg-mcp-revoke">' . esc_html__( 'Revoke key', 'fatalguard' ) . '</button>';
		echo '<p class="fg-check__d">' . esc_html__( 'A key is active (shown only once at creation). Regenerating replaces it — the old key stops working immediately.', 'fatalguard' ) . '</p>';
	} else {
		echo '<button type="submit" name="op" value="generate" class="button button-primary button-hero fg-mcp-gen">' . esc_html__( 'Generate API key', 'fatalguard' ) . '</button>';
		echo '<p class="fg-check__d">' . esc_html__( 'Generate a key, then copy a client snippet below.', 'fatalguard' ) . '</p>';
	}
	echo '</form>';

	// Copy-and-go client snippets — endpoint + key baked in (key present only
	// right after minting; otherwise a placeholder to paste your saved key into).
	$cfg_key = $just_key ? $just_key : 'PASTE-YOUR-API-KEY-HERE';
	fatalguard_mcp_code_block(
		__( 'Claude Code (run in your terminal)', 'fatalguard' ),
		fatalguard_mcp_client_cmd( $url, $cfg_key )
	);
	// B172: Emit per-client config blocks — each client uses a different schema.
	fatalguard_mcp_code_block(
		__( 'Cursor — mcp.json (mcpServers)', 'fatalguard' ),
		fatalguard_mcp_client_json_cursor( $url, $cfg_key )
	);
	fatalguard_mcp_code_block(
		__( 'VS Code — .vscode/mcp.json (servers)', 'fatalguard' ),
		fatalguard_mcp_client_json_vscode( $url, $cfg_key )
	);
	fatalguard_mcp_code_block(
		__( 'Claude Desktop — claude_desktop_config.json (via mcp-remote)', 'fatalguard' ),
		fatalguard_mcp_client_json_claude_desktop( $url, $cfg_key )
	);

	echo '</div></section>';
}

/** One labelled, copyable code block. */
function fatalguard_mcp_code_block( string $label, string $code ): void {
	echo '<p class="fg-code-label">' . esc_html( $label ) . '</p>';
	echo '<div class="fg-code-wrap">';
	echo '<button type="button" class="button fg-copy" data-fg-copy>' . esc_html__( 'Copy', 'fatalguard' ) . '</button>';
	echo '<pre class="fg-code"><code>' . esc_html( $code ) . '</code></pre>';
	echo '</div>';
}

/** The Claude Code one-liner (endpoint + key baked in). */
function fatalguard_mcp_client_cmd( string $url, string $key ): string {
	// B113: Quote the URL so that zsh does not glob-expand '?' in ?rest_route= URLs.
	return 'claude mcp add --transport http fatalguard "' . $url . '" --header "X-FatalGuard-Key: ' . $key . '"';
}

/**
 * B172: Per-client MCP config helpers.
 *
 * Each MCP client uses a different config schema:
 * - Cursor:        mcpServers → { type, url, headers }
 * - VS Code:       servers   → { type, url, headers }  (top-level key differs)
 * - Claude Desktop: stdio via mcp-remote bridge (no native type:http support)
 */

/** Cursor mcp.json — uses mcpServers with type:http. */
function fatalguard_mcp_client_json_cursor( string $url, string $key ): string {
	return (string) wp_json_encode(
		array(
			'mcpServers' => array(
				'fatalguard' => array(
					'type'    => 'http',
					'url'     => $url,
					'headers' => array( 'X-FatalGuard-Key' => $key ),
				),
			),
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	);
}

/** VS Code .vscode/mcp.json — uses top-level "servers" key (not mcpServers). */
function fatalguard_mcp_client_json_vscode( string $url, string $key ): string {
	return (string) wp_json_encode(
		array(
			'servers' => array(
				'fatalguard' => array(
					'type'    => 'http',
					'url'     => $url,
					'headers' => array( 'X-FatalGuard-Key' => $key ),
				),
			),
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	);
}

/** Claude Desktop — requires the mcp-remote stdio bridge (no native HTTP MCP support). */
function fatalguard_mcp_client_json_claude_desktop( string $url, string $key ): string {
	return (string) wp_json_encode(
		array(
			'mcpServers' => array(
				'fatalguard' => array(
					'command' => 'npx',
					'args'    => array(
						'mcp-remote',
						$url,
						'--header',
						'X-FatalGuard-Key: ' . $key,
					),
				),
			),
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	);
}

/** admin-post handler: generate or revoke the key, then redirect back. */
function fatalguard_mcp_handle_key_action(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'fatalguard' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'fatalguard_mcp_key' );

	$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
	if ( 'generate' === $op ) {
		fatalguard_mcp_generate_key();
	} elseif ( 'revoke' === $op ) {
		fatalguard_mcp_revoke_key();
	}

	// Back to the AI (MCP) tab specifically (the hash drives the client-side tabs).
	$url = function_exists( 'fatalguard_settings_page_url' ) ? fatalguard_settings_page_url() : admin_url( 'admin.php?page=fatalguard' );
	wp_safe_redirect( $url . '#ai' );
	exit;
}
