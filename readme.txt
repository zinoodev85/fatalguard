=== PHP Error Log Viewer & AI (MCP), Debug Log, Fatal Error Alerts – FatalGuard ===
Contributors: zinodev
Tags: error log, debug log, fatal error, telegram, mcp
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One-tap fix for a crashed WordPress site — read your debug & error log, catch the fatal error behind the white screen, get instant Telegram alerts.

== Description ==

**FatalGuard** turns WordPress error handling into something you can actually see and act on. It reads your **debug log** and **PHP error log** from a fast admin-bar viewer, **captures the real fatal error** hiding behind the "There has been a critical error" **white screen of death (WSOD)**, and sends an instant **fatal error notification** to **Telegram** (Slack, email and Discord in Pro).

Every fatal notification includes a **one-tap recovery link**. Open it on your phone to deactivate the crashing plugin and bring the site back — it works even when wp-admin is down and you can't log in. Nothing is deleted; re-enable the plugin once it's fixed.

And you can debug your site by **chatting with an AI** — free. FatalGuard runs its own **read-only MCP server**: generate an API key, connect Claude, Cursor, VS Code or any MCP client, and ask in plain English — *"what's crashing my site?"*, *"which warnings keep coming back?"* — answered from your site's real logs and captured fatals. No other plugin needed.

FatalGuard manages file logging for you — flip one switch and it writes the standard `WP_DEBUG` constants to `wp-config.php` (restoring your original values when you switch it off). And it is built to **never brick your site**: the fatal-error handler always falls back to WordPress's own recovery, with a file kill-switch and self-disarm sentinel as safety nets.

= Free error log & debug log viewer, fatal error alerts & one-tap recovery =

* **Admin-bar log viewer** — read your WordPress `debug.log` and PHP `error_log` in a fast tabbed overlay with live tail, no FTP or SSH.
* **Grouped errors** — repeated errors collapse into one entry with a count and last-seen, so the error that matters isn't buried in noise.
* **Search & filter** log entries to find the error that matters.
* **One-switch file logging** — FatalGuard writes the standard `WP_DEBUG_LOG` constants to `wp-config.php` for you, and restores your originals when you switch it off.
* **Fatal error capture** — catches the actual PHP fatal error behind the WordPress white screen / critical-error page and shows you the message, file and line.
* **Instant Telegram error alert** — get a message the moment a fatal error happens, with built-in anti-spam (dedupe, burst-cap and a circuit-breaker) so a fatal loop can never flood your phone. Telegram alerts are included in the free plugin.
* **One-tap recovery** — the fatal alert includes a signed link that deactivates only the crashing plugin and restores the site. It runs before regular plugins load, so it works even when wp-admin is down — no FTP, SSH or login needed. Nothing is deleted; re-enable the plugin when it's fixed.
* **JavaScript error capture** — front-end JS errors are logged in your admin session (requires the admin bar; visitor-side JS is not captured).
* **Automatic secret redaction** — database passwords, auth keys, API keys and tokens are scrubbed from your logs and alerts before they're ever shown or sent.
* **Private, web-protected log storage** — logs live in a hardened directory that can't be read from a browser, with non-guessable paths.
* **Production-safe by design** — full error detail only in development; on production your visitors always get WordPress's own recovery page, never a leak.
* **Never-brick design** — a self-installing, self-healing drop-in that always chains to WordPress's native recovery. A DISABLE kill-switch file is your no-SSH escape hatch.
* **Status / diagnostics tab** — see exactly what logging is on and where the log lives.
* **AI access (MCP)** — FatalGuard's own **read-only MCP server**, no other plugin needed. Generate a **revocable API key**, paste the ready-made snippet into Claude Code, Claude Desktop, Cursor or VS Code, and ask what's crashing your site. The AI can read logging status, your logs (secrets redacted), grouped errors and captured fatals — it cannot change anything.

= FatalGuard Pro =

Everything in free, plus:

* **Searchable error history** — every fatal kept in a database, grouped with counts and first/last-seen (free is live tail only). **Your AI also reads your full error history** through the MCP server: *"What's the most recurring error this month?"*, *"What started failing after my last deploy?"*
* **Multi-channel alerts** — Slack, email, Discord and custom webhooks alongside Telegram.
* **Multiple Telegram targets** with per-site routing.
* **Stealth mode** — hide the plugin from other administrators.
* **Weekly email digest** of your site's errors.

On the roadmap: a **central dashboard** — every client site's fatal errors in one view, with remote one-tap recovery for any of them.

[Learn more about FatalGuard Pro »](https://fatalguard.com/pro/?utm_source=wordpress.org&utm_medium=readme&utm_campaign=fatalguard-pro)

= What FatalGuard does =

FatalGuard **catches the fatal error** that took the site down, tells you about it, and gives you a **one-tap link to fix it** — deactivating the crashing plugin even when you can't log in. It's a **debug log viewer**, a fatal-error monitor, an alerting system, and a recovery tool in one, engineered so the error handler itself can never white-screen your site.

== Installation ==

1. Upload the `fatalguard` folder to `/wp-content/plugins/`, or install FatalGuard from **Plugins → Add New**.
2. Activate the plugin. Fatal-error capture and alerts arm automatically; enable file logging with one switch under **FatalGuard → General**.
3. Open the **FatalGuard** item in your admin bar to view logs.
4. (Optional) Open **FatalGuard** and run the Telegram connect wizard: create a bot in @BotFather, paste its token, and press **Start** — FatalGuard detects your chat automatically, no chat-id hunting. Every fatal alert then includes the one-tap recovery link.

== Frequently Asked Questions ==

= Why is my WordPress site showing a white screen or "There has been a critical error"? =
That page hides a PHP fatal error. FatalGuard captures the real fatal error behind the white screen of death and shows you the message, file and line — and can send it to Telegram the instant it happens, with a one-tap link to recover the site by switching off the plugin that caused the critical error.

= How do I read the WordPress debug log without FTP? =
Install FatalGuard and open its admin-bar item. It shows your `debug.log` and PHP `error_log` in a searchable, tabbed viewer inside wp-admin — no FTP, SSH or cPanel needed.

= Do I need to edit wp-config.php or turn on WP_DEBUG? =
Not by hand. Flip the file-logging switch and FatalGuard writes the standard `WP_DEBUG` / `WP_DEBUG_LOG` constants to `wp-config.php` for you — pointing the log at its protected folder — and restores your original values when you switch it off. If `wp-config.php` isn't writable on your host, FatalGuard shows you the exact lines to paste.

= Can this plugin break my site? =
No. The fatal-error handler always falls back to WordPress's built-in recovery. If anything ever goes wrong, dropping a `DISABLE` file removes the handler — a recovery path that works even without SSH.

= How do I get Telegram alerts for fatal errors? =
Open FatalGuard and run the Telegram connect wizard: create a bot in @BotFather, paste its token, and press Start. FatalGuard detects your chat automatically and sends a non-blocking message whenever a fatal error is captured — no chat-id hunting.

= My site is completely down and I can't log in — how do I fix it? =
When a plugin fatals every page, wp-admin goes down too. Your FatalGuard fatal alert includes a secure recovery link: open it on your phone and tap once to deactivate the crashing plugin. It runs from a must-use loader that loads before regular plugins, so it works even when the rest of WordPress can't. Nothing is deleted — re-enable the plugin from **Plugins** once it's fixed.

= Is the one-tap recovery link safe? =
Yes. Each link is signed with your site's secret keys, does only one thing (deactivate the one named plugin), and expires after 4 hours. It's the same approach as WordPress core's Recovery Mode email link — a signed link stands in for the login you can't do while the site is down.

= Does it support Slack, email or Discord alerts? =
Slack, email, Discord and webhook alerts are part of FatalGuard Pro. Telegram is included free.

= Can I use AI (Claude, Cursor) to debug my WordPress errors? =
Yes — free. FatalGuard runs its own **read-only MCP server** inside WordPress; no other plugin is needed. Open FatalGuard → AI (MCP), generate an API key, and copy the ready-made snippet for Claude Code, Claude Desktop, Cursor or VS Code. Then ask in plain English: "what's crashing my site?", "which warnings keep coming back?". The AI can read logging status, your logs (secrets redacted), grouped errors and captured fatals, but it cannot change anything. The key is revocable at any time. It works while WordPress can still load; for a site that is fully down, use the one-tap recovery link. Pro adds the searchable error history, which the AI can then read too.

= Where are the logs stored? =
In a private, web-protected directory with a non-guessable path — not the standard WordPress debug.log location. Browsers can't read the directory directly. The Status tab shows the exact path on your server.

= How do I get notified when WordPress has a fatal error? =
FatalGuard captures every PHP fatal error and sends an instant Telegram alert straight from the crashing request (free) — so you hear about a broken site before your visitors or client do. Pro adds Slack, email, Discord and webhook alerts, sent on the next successful page load.

= What causes the WordPress white screen of death (WSOD)? =
The white screen (or "There has been a critical error on this website") is almost always a PHP fatal error — a plugin/theme conflict, a memory limit, or bad code. FatalGuard captures the exact fatal error, file and line behind that screen so you can fix the real cause fast.

== External services ==

FatalGuard does not connect to any external service on its own. It contacts a third-party service only when **you** configure one, and only to deliver the alerts you asked for.

**Telegram (optional — free tier).** If you connect a Telegram bot in the settings, FatalGuard talks to the Telegram Bot API (`https://api.telegram.org`) in four cases: (1) during the connect wizard, your bot token is used to detect the chat you pressed Start in; (2) when you click "Send test message" while setting it up; (3) whenever a PHP fatal error is captured on your site — this sends the alert message; and (4) once a day, a silent health check validates that the bot token and chat still work, so a revoked bot is surfaced instead of failing quietly (no message is posted to the chat). An alert message contains the details needed to diagnose the crash — the error message, the file path and line number, the request URL, the site URL, the timestamp and the PHP version (secrets such as keys and passwords are scrubbed out first). Nothing is sent unless you have entered a bot token; messages go only to the Telegram chat you chose.

This service is provided by Telegram, not by FatalGuard. See Telegram's Terms of Service (https://telegram.org/tos) and Privacy Policy (https://telegram.org/privacy).

**Slack, Discord and custom webhook alerts (optional — Pro).** FatalGuard Pro can additionally deliver the same fatal-error alert to a Slack incoming webhook (`https://hooks.slack.com`), a Discord webhook (`https://discord.com/api/webhooks`) or any custom webhook URL you enter. As with Telegram, a message is sent only to an endpoint **you** configure, only when you send a test or when a fatal error is captured, and it contains the same diagnostic fields (error message, file, line, request URL, site URL, timestamp, PHP version — with secrets scrubbed). These channels are inactive in the free plugin. Slack is provided by Slack Technologies — see Slack's Terms (https://slack.com/terms-of-service) and Privacy Policy (https://slack.com/privacy-policy). Discord is provided by Discord Inc. — see Discord's Terms (https://discord.com/terms) and Privacy Policy (https://discord.com/privacy). A custom webhook sends data to whatever server URL you supply; its handling is governed by that server's own terms.

**AI access (MCP, optional).** The MCP server is an endpoint on your own site (`/wp-json/fatalguard/v1/mcp`). FatalGuard never contacts an AI service itself: it only answers requests from an AI client **you** connect with your API key, and it is off until you generate a key. What the AI provider does with the data your client sends it is governed by that provider's terms.

FatalGuard sends no analytics, telemetry or usage data anywhere, and does not phone home for updates (updates come through WordPress.org).

== Screenshots ==

1. Debug log & error log viewer in the WordPress admin bar — grouped errors with counts, no FTP.
2. Read your PHP error_log and debug.log with a live tail — every warning, notice and fatal, no SSH.
3. Fatal error captured behind the WordPress white screen of death — the real error message, file and line.
4. Instant Telegram error notification the moment a fatal error happens.
5. One-tap recovery page — deactivate the crashing plugin and restore the site from your phone, no login needed.
6. FatalGuard settings — the Telegram connect wizard and automatic file logging, no wp-config editing.
7. Ask AI what's crashing your site — connect Claude, Cursor or any MCP client to FatalGuard's own read-only MCP server.
8. "Which deprecations are in my theme?" — the AI reads your live errors through FatalGuard's read-only MCP server.

== Changelog ==

= 1.3.2 =
* Fix: a stack trace now stays with its error in the grouped log view and in the AI (MCP) error list, instead of each trace line showing as its own error.

= 1.3.1 =
* Listing: name now shows AI (MCP) access. No code changes.

= 1.3.0 =
* New, free: AI access (MCP). Connect Claude, Cursor, VS Code or any MCP client to FatalGuard's own read-only MCP server with a revocable API key, and ask what is crashing your site. Six tools: status, log sources, read a log, grouped errors, recent fatal errors, one fatal error in detail.
* Pro: the AI can also query your full error history.

= 1.2.6 =
* Listing title now leads with what the plugin does: PHP error log viewer, debug log and fatal error alerts.
* Readme: white screen of death (WSOD) wording in the description and FAQ.

= 1.2.5 =
* Sturdier startup: optional log sources load only when their files are present, so a partial or customised install can no longer stop FatalGuard from loading.

= 1.2.4 =
* File logging and the show-errors toggle now use the standard WP_DEBUG constants: FatalGuard writes them to wp-config.php on your explicit toggle (backing up and restoring your original values) and WordPress core applies them — the plugin no longer changes any PHP setting at runtime.
* Out-of-memory fatals are now captured using pre-reserved memory instead of raising memory_limit.
* Recovery-screen styles delivered through the WordPress styles API; all dynamic output escaped at the point of output.
* Directory handling cleanup: plugin paths derived from the plugin's own location, uploads paths from wp_upload_dir().

= 1.2.3 =
* Log storage moved to a protected folder in the uploads directory; only the two files WordPress's architecture requires (the fatal-error handler and the recovery must-use loader) remain in wp-content.
* Runtime PHP error logging is now tied to the plugin's own logging state.
* Enqueue-based admin styles/scripts, hardened request-data handling, and readme/i18n housekeeping for WordPress.org standards.

= 1.2.2 =
* Webhook alerts now carry the one-tap recovery link.
* Log-viewer Alerts tab lists every alert channel with live status.
* Hardened request-data and file handling to meet WordPress.org coding standards.

= 1.2.1 =
* Settings: multi-field saves no longer return stale values - the save-sanitizer dedupe is now keyed per input, not a blanket once-per-request guard (B154).
* Settings: history retention now clamps to 0-3650 days at both ends.

= 1.2.0 =
* Hardened concurrency safety across fatal-capture, alert dispatch and log rotation — no duplicate or lost alerts under load.
* Expired recovery screens now offer a secure "Request a new recovery link" action (signature re-verified).
* Full localization: recovery screens + admin UI are now translatable, shipping with 10 languages (ES, FR, DE, PT-BR, IT, NL, RU, JA, ZH-CN, AR).
* Reliability fixes to delivery-failure tracking and Telegram/webhook health checks.

= 1.1.0 =
* Alert delivery now detects and surfaces failed Telegram/channel sends (previously dropped silently)
* New: daily silent Telegram health check validates the bot token + chat without posting, so a revoked token is surfaced within 24 hours
* Pro: conservative per-channel liveness checks for Slack, Discord and custom webhooks
* Dashboard widget now warns when an alert channel is failing instead of always showing "on"

= 1.0.0 =
* Initial release: admin-bar debug log & error log viewer, PHP error log reader, fatal error capture behind the white screen of death, one-tap recovery link to deactivate a crashing plugin and restore a down site, guided Telegram connect wizard with automatic chat detection, automatic file logging with no wp-config editing, never-brick self-installing drop-in, and free Telegram fatal error alerts.
