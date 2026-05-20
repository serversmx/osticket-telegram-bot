# Changelog

All notable changes to **osTicket Telegram Bot Notifications** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/RenatoAscencio/osticket-telegram-bot/compare/v0.1.2...HEAD
[0.1.2]: https://github.com/RenatoAscencio/osticket-telegram-bot/releases/tag/v0.1.2
[0.1.1]: https://github.com/RenatoAscencio/osticket-telegram-bot/releases/tag/v0.1.1
[0.1.0]: https://github.com/RenatoAscencio/osticket-telegram-bot/releases/tag/v0.1.0
