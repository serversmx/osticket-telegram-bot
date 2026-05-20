# Changelog

All notable changes to **osTicket Telegram Bot Notifications** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.8] - 2026-05-20

### Added
- **`plugin/scp-apps-landing.php.example`** — a generic landing page for the staff "Applications" tab. osTicket sets the Applications tab's default `href` to `apps.php` (see `include/class.nav.php`), but the file is **not** shipped with osTicket. Clicking the tab text (instead of hovering for the dropdown) gives a 404. This file fills that gap by listing every plugin-registered staff app from `Application::getStaffApps()`. Not Telegram-specific — drop it once and any plugin's apps appear there.

### Install

```bash
cp /path/to/osticket/include/plugins/telegram-bot/scp-apps-landing.php.example \
   /path/to/osticket/scp/apps.php
chown <web-user>:<web-group> /path/to/osticket/scp/apps.php
chmod 644 /path/to/osticket/scp/apps.php
```

After that, both entry points work:

- **Hover Applications → Telegram** → goes to `/scp/link-telegram.php` (direct).
- **Click Applications tab** → goes to `/scp/apps.php` → lists Telegram (+ any other registered app) → click to open.

## [0.1.7] - 2026-05-20

### Added
- **Staff Applications-menu entry** via `Application::registerStaffApp()` — osTicket's plugin-native extension point for staff navigation. The plugin's `bootstrap()` now registers a "Telegram" item that appears under the staff "Applications" dropdown in the top nav, pointing at `scp/link-telegram.php`. **No core file modification, survives osTicket upgrades.**
- The "Applications" tab itself materializes in the staff nav as soon as at least one plugin registers an app (osTicket only shows the tab when `getStaffApps()` is non-empty).

### Notes
- `class.app.php` is normally required lazily by `class.nav.php` at render time, which is too late for plugins. `registerStaffNav()` requires it explicitly first.
- PHP 8.x compatibility: `Application::registerStaffApp()` is declared without `static` but mutates a static property, so calling it statically throws under PHP 8.x. We instantiate (`new Application()`) and call on the instance — the underlying static property still ends up populated.
- The `scripts/apply-staff-profile-patch.sh` script from v0.1.6 is now **optional** — only run it if you also want a visible button block at the top of the staff profile page in addition to the Applications-menu entry. Most installs will be happy with just the menu entry.

## [0.1.6] - 2026-05-20

### Added
- **Staff link-telegram page is now a full UI** (`scp/link-telegram.php`) — previously a 302 redirect, now renders inside the standard osTicket staff header/footer with a status badge ("Vinculado ✓" / "No vinculado") and explicit buttons to link or unlink. POSTs back to itself to trigger the redirect or the unlink. No more "what just happened" page.
- **`scripts/apply-staff-profile-patch.sh`** — idempotent patcher that adds a prominent "🔗 Gestionar Telegram" button block to `include/staff/profile.inc.php`, right above the form. Includes pristine backup (`.pre-tgbot`), `php -l` lint gate before writing, restore instructions in the output. See `docs/staff-profile-patch.md` for why a core-file patch is needed (osTicket 1.18 has no plugin extension point for injecting UI into the staff profile, and plugin bootstrap runs before the global `$ost` exists so `addExtraHeader` from there is a no-op). The script is the cleanest way to opt into the in-profile button without an unreliable JS-injection hack — admins run it explicitly, idempotency makes it safe to re-run after osTicket upgrades.
- `docs/staff-profile-patch.md` documents the patch, rationale, what gets inserted, and re-application after osTicket upgrades.

## [0.1.5] - 2026-05-20

### Added
- **Staff (admin) account linking** — parallel flow to the customer linking added in v0.1.4. Each staff member can self-enroll into the admin notification recipient list with a single click + Start in Telegram, instead of an admin having to copy their numeric chat_id into the `admin_chat_ids` config field.
  - New tables (auto-created on first use): `<prefix>telegram_staff_links` (staff_id ↔ chat_id) and `<prefix>telegram_staff_link_tokens` (one-shot tokens with the same TTL as customer tokens).
  - New plugin method `generateStaffLinkUrl($staffId)` mints a staff-scoped token and returns the t.me deep link.
  - `handleStart()` now tries `consumeToken()` first (customer table), then falls back to `consumeStaffToken()` — tokens are in separate tables so each resolves unambiguously.
  - `sendToAdmins()` merges the manual `admin_chat_ids` config list with `allStaffChatIds()` from the links store (deduplicated). Staff members who link via the new flow start receiving admin notifications immediately, no config edit required.
  - `/unlink` and `/status` now check both user and staff tables and report accurately.
- **`plugin/link-telegram-staff.php.example`** — staff-facing redirect intended for the `scp/` directory. Authenticates via `staff.inc.php`, calls `generateStaffLinkUrl($thisstaff->getId())`, redirects to t.me. Same one-click UX as the customer version.

### Internal
- `UserLinkStore` gained the parallel staff API: `issueStaffToken`, `consumeStaffToken`, `linkStaff`, `unlinkStaffByChat`, `unlinkStaffById`, `chatIdForStaff`, `staffIdForChat`, `allStaffChatIds`. `pruneExpiredTokens` now prunes both token tables.

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

[Unreleased]: https://github.com/RenatoAscencio/osticket-telegram-bot/compare/v0.1.8...HEAD
[0.1.8]: https://github.com/RenatoAscencio/osticket-telegram-bot/releases/tag/v0.1.8
[0.1.7]: https://github.com/RenatoAscencio/osticket-telegram-bot/releases/tag/v0.1.7
[0.1.6]: https://github.com/RenatoAscencio/osticket-telegram-bot/releases/tag/v0.1.6
[0.1.5]: https://github.com/RenatoAscencio/osticket-telegram-bot/releases/tag/v0.1.5
[0.1.4]: https://github.com/RenatoAscencio/osticket-telegram-bot/releases/tag/v0.1.4
[0.1.3]: https://github.com/RenatoAscencio/osticket-telegram-bot/releases/tag/v0.1.3
[0.1.2]: https://github.com/RenatoAscencio/osticket-telegram-bot/releases/tag/v0.1.2
[0.1.1]: https://github.com/RenatoAscencio/osticket-telegram-bot/releases/tag/v0.1.1
[0.1.0]: https://github.com/RenatoAscencio/osticket-telegram-bot/releases/tag/v0.1.0
