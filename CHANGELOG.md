# Changelog

All notable changes to **osTicket Telegram Bot Notifications** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/RenatoAscencio/osticket-telegram-bot/commits/main
