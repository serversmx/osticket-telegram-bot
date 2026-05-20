# Changelog

All notable changes to **osTicket Telegram Bot Notifications** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.4] - 2026-05-20

### Added
- **`plugin/link-telegram.php.example`** — a customer-facing redirect script intended for the osTicket public root. Closes the missing UX piece in the deep-link account-linking flow: previously the backend (`generateLinkUrl()`, the `<prefix>telegram_link_tokens` table, the webhook handler for `/start <token>`) was complete, but there was no obvious URL a customer could hit to actually trigger the flow. With this script deployed:
  1. Customer (already logged in to osTicket) opens `https://<your-osticket>/link-telegram.php`.
  2. The script bootstraps osTicket via `client.inc.php` (which also enforces auth — redirects to `login.php` if not logged in).
  3. Looks up the active plugin instance, calls `generateLinkUrl($userId)` to mint a one-shot 15-minute TTL token, redirects the browser to `https://t.me/<bot>?start=<token>`.
  4. Customer presses Start in Telegram → webhook fires → `(user_id, chat_id)` row in `<prefix>telegram_links`.
  At no point does the customer see the chat_id or have to type anything — they click one link and press Start.
- `docs/user-linking.md` updated with the install-the-redirect-file step and the recommended hint text to put on the manual `chat_id` form field so customers know to use the redirect.

### Notes
- The redirect script is provided as `*.example` so admins must opt-in by copying it to a public path of their choice (default suggestion: `<root>/link-telegram.php`). Same pattern as `webhook-proxy.php.example`.

## [0.1.3] - 2026-05-20

### Fixed
- **Signal handlers were reading an empty config.** `PluginManager::bootstrap()` clears `$plugin->config = null` after running each plugin's instance bootstrap, so any later `$this->getConfig()` call (from a signal handler or `processUpdate`) returned a default-namespaced empty config — bot would silently fail to read its own token, chat IDs, templates, etc. Added a `cachedCfg` snapshot taken via a new `cfg()` helper that side-loads from `getActiveInstances()` the first time it's called and survives the clear. All 23 internal `$this->getConfig()` callsites now go through `cfg()`.
- **`webhook.php` had the same bug**, plus it was not binding the config to a specific instance at all. Now explicitly looks up the first active `PluginInstance` and calls `$plugin->getConfig($instance)` to side-load before checking the `X-Telegram-Bot-Api-Secret-Token` header. Without this, the webhook accepted any request regardless of the configured secret. Returns 500 with a logged hint if no active instance exists.

### Internal
- `parent::getConfig()` is now used inside `cfg()` to avoid recursion if subclasses ever override `getConfig`.

## [0.1.2] - 2026-05-19

### Fixed
- **Class-name collision with `osticket-evolution-api` caused a white page when admin tried to enable the plugin.** Both plugins bundled identically-named lib classes (`EvoSentryReporter`, `EvoLogRedactor`), so the second to load triggered a fatal `Cannot declare class X, because the name is already in use`. Renamed Telegram's copies to `TgSentryReporter` / `TgLogRedactor`. Each plugin now owns its own namespace-distinct copies and both can be enabled simultaneously on the same osTicket install.

### Internal
- All references in `telegram.php`, `lib/TelegramBotClient.php`, and the three affected test files updated to the new names.
- Tests still pass (81/81 assertions across 5 files).

## [0.1.1] - 2026-05-19

### Fixed
- **`webhook.php` bootstrap was incomplete.** Previously called `bootstrap.php` directly + `Bootstrap::loadConfig()` + `Bootstrap::loadCode()`, which left `FILTER_ACTION_TABLE` and other `defineTables()`-derived constants undefined → fatal error when osTicket's class loader chained into `class.filter_action.php`. Now requires `main.inc.php` (the canonical osTicket entry point), which runs the full bootstrap sequence: `loadConfig → defineTables → i18n_prep → loadCode → connect`.

### Added
- **`plugin/webhook-proxy.php.example`** — a one-line proxy admins copy to the osTicket public root so Telegram can reach the webhook. Necessary because osTicket's `include/.htaccess` (`Deny from all`) blocks direct HTTP access to the plugin's `webhook.php`. PHP `require_once` at the filesystem level bypasses the `.htaccess` HTTP-layer rule.
- `docs/webhook-setup.md` updated with a new section walking through proxy install + the resulting `webhook_public_url` value.

## [0.1.0] - 2026-05-19

### Added
- Initial Telegram Bot API client (`sendMessage`, `getMe`, `setWebhook`, `deleteWebhook`, `getUpdates`).
- HTTP retries on 429 / 5xx / network errors with exponential backoff. Honors `Retry-After`.
- Per-event-per-audience matrix: ticket created, customer reply, staff reply, status changed, assignment changed — each independently toggleable for client and admin.
- Customer account linking via Telegram bot deep-link (`https://t.me/<bot>?start=<token>`). Generated tokens are TTL-scoped (default 15 min) and one-shot.
- Manual fallback: customer can paste their chat_id into a user-form field (no webhook required).
- Inline keyboards on outbound messages: configurable URL buttons for "View ticket" and "Reply to ticket". Toggleable per template.
- Two parse modes: `MarkdownV2` (with safe escaping) or `HTML`. Plain text fallback when admin disables formatting.
- Public webhook endpoint (`webhook.php`) processes `/start <token>`, `/unlink`, and `/status` commands. Secured with the `X-Telegram-Bot-Api-Secret-Token` header.
- User opt-in honored via configurable Contact-Information form field (default `telegram_opt_in`).
- Silent send option (`disable_notification`) for low-priority events.
- Optional Sentry integration via the same envelope client as the Evolution API plugin (no Composer required).
- Local Docker test stack (osTicket v1.18.3 + MariaDB).
- Generic SSH + rsync deploy script driven by env vars.
- English + Spanish READMEs; architecture / configuration / linking / inline-buttons / Sentry / deploy docs.

### Security
- `bot_token` and `webhook_secret_token` are `PasswordField` (masked in admin UI).
- Sentry DSN is `PasswordField`.
- All log levels go through `EvoLogRedactor` — chat_ids partially masked, message bodies truncated with length prefix, secrets replaced with `[REDACTED]`.
- `SECURITY.md` documents threat model, webhook trust boundary, accepted risks, and responsible-disclosure channel.

[Unreleased]: https://github.com/RenatoAscencio/osticket-telegram-bot/compare/v0.1.4...HEAD
[0.1.4]: https://github.com/RenatoAscencio/osticket-telegram-bot/releases/tag/v0.1.4
[0.1.3]: https://github.com/RenatoAscencio/osticket-telegram-bot/releases/tag/v0.1.3
[0.1.2]: https://github.com/RenatoAscencio/osticket-telegram-bot/releases/tag/v0.1.2
[0.1.1]: https://github.com/RenatoAscencio/osticket-telegram-bot/releases/tag/v0.1.1
[0.1.0]: https://github.com/RenatoAscencio/osticket-telegram-bot/releases/tag/v0.1.0
