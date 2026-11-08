<?php
/**
 * FatalGuard — MCP server (bootstrap). Free + Pro.
 *
 * Exposes the site's error logs and captured fatals to an external AI assistant
 * (Claude Code, Claude Desktop, Cursor, VS Code…) over the Model Context Protocol.
 * It is FatalGuard's own in-WordPress endpoint — POST /wp-json/fatalguard/v1/mcp —
 * speaking minimal MCP JSON-RPC (initialize / tools/list / tools/call). No other
 * plugin and no separate Node process are needed.
 *
 * READ-ONLY: the tools wrap the module's read callbacks (abilities.php) and, in
 * Pro, the history table. Auth is a scoped, revocable, read-only FatalGuard API
 * key — never a manage_options session — so a leaked key can only READ.
 *
 * SCOPE LIMIT (by design): this is a normal REST route, so it only answers while
 * WordPress still boots. On a full white screen /wp-json also 500s — the MCP is a
 * monitoring / triage channel, NOT a rescue channel for an already-dead site
 * (that stays the job of the signed recovery links).
 *
 * Loaded by fatalguard.php after the module.
 *
 * @package FatalGuard
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/mcp/auth.php';
require_once __DIR__ . '/mcp/tools.php';
require_once __DIR__ . '/mcp/mcp-server.php';
require_once __DIR__ . '/mcp/settings-ui.php';
