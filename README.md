# FatalGuard — PHP error log viewer, fatal error alerts and MCP server for WordPress

FatalGuard is a WordPress plugin. It shows your `debug.log` and PHP error log in wp-admin,
catches the fatal error behind the "critical error" white screen, sends Telegram alerts with
a one-tap recovery link, and runs its own **read-only MCP server**, so Claude, Cursor or
VS Code can read your site's errors.

This repository is a read-only mirror of the free plugin, version 1.3.1. Development
happens elsewhere; issues and questions go to the
[support forum](https://wordpress.org/support/plugin/fatalguard/).

## Install

Install it from wordpress.org, so your site gets automatic updates:

- In wp-admin: **Plugins → Add New**, search for "FatalGuard", **Install**, **Activate**.
- Or download it from <https://wordpress.org/plugins/fatalguard/>.

A zip from this repository works too (**Plugins → Add New → Upload Plugin**). It uses the
same slug and has no `Update URI` header, so WordPress updates it from wordpress.org.

## Connect an AI client (MCP)

1. In wp-admin, open **FatalGuard → AI (MCP)** and press **Generate API key**. Copy the key:
   it is shown only once. The same page shows these snippets with your URL and key filled in.
2. Add the server to your client. Replace `example.com` with your site and paste your key.

Endpoint: `POST https://<your-site>/wp-json/fatalguard/v1/mcp` (streamable HTTP).
The key goes in the `X-FatalGuard-Key` header. Some Apache hosts strip
`Authorization: Bearer`, so use this header.

**Claude Code** (run in your terminal)

```sh
claude mcp add --transport http fatalguard "https://example.com/wp-json/fatalguard/v1/mcp" --header "X-FatalGuard-Key: PASTE-YOUR-API-KEY-HERE"
```

**Cursor** — `mcp.json`

```json
{
  "mcpServers": {
    "fatalguard": {
      "type": "http",
      "url": "https://example.com/wp-json/fatalguard/v1/mcp",
      "headers": {
        "X-FatalGuard-Key": "PASTE-YOUR-API-KEY-HERE"
      }
    }
  }
}
```

**VS Code** — `.vscode/mcp.json`

```json
{
  "servers": {
    "fatalguard": {
      "type": "http",
      "url": "https://example.com/wp-json/fatalguard/v1/mcp",
      "headers": {
        "X-FatalGuard-Key": "PASTE-YOUR-API-KEY-HERE"
      }
    }
  }
}
```

**Claude Desktop** — `claude_desktop_config.json` (via mcp-remote)

```json
{
  "mcpServers": {
    "fatalguard": {
      "command": "npx",
      "args": [
        "mcp-remote",
        "https://example.com/wp-json/fatalguard/v1/mcp",
        "--header",
        "X-FatalGuard-Key: PASTE-YOUR-API-KEY-HERE"
      ]
    }
  }
}
```

Then ask: "What's crashing my site?" or "Which warnings keep coming back?"

### Tools

All six are read-only. The AI cannot change anything on your site.

| Tool | What it returns |
|---|---|
| `status` | Logging status and FatalGuard settings |
| `list_log_sources` | The logs FatalGuard can read, with size |
| `tail_log` | The newest lines of one log, secrets redacted |
| `list_errors` | Errors grouped with counts and last seen |
| `list_recent_fatals` | Recently captured fatal errors |
| `get_fatal` | One fatal error in detail |

The server works while WordPress can still load. For a site that is fully down, use the
recovery link from the fatal alert. Revoke the key at any time on the same page.

## License

GPLv2 or later. See [LICENSE.txt](LICENSE.txt).
