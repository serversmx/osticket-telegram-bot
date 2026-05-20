# osTicket Telegram Bot Notifications

[![License: GPL v2](https://img.shields.io/badge/License-GPL_v2-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.txt)
[![osTicket](https://img.shields.io/badge/osTicket-%E2%89%A5%201.17-orange.svg)](https://github.com/osTicket/osTicket)
[![Telegram Bot API](https://img.shields.io/badge/Telegram_Bot_API-supported-26A5E4.svg)](https://core.telegram.org/bots/api)

> *Read this in [Spanish / Español](./README.es.md).*

osTicket plugin that sends **Telegram** notifications to both end users and administrators on ticket lifecycle events, with **inline keyboards** (View ticket / Reply buttons), per-event × per-audience toggles, and a **bot deep-link account-linking flow** so customers connect their Telegram by clicking a single link.

Designed as a sibling to [`osticket-evolution-api`](https://github.com/RenatoAscencio/osticket-evolution-api): same architecture, security posture, and quality bar — just speaking Telegram Bot API instead of Evolution.

---

## Features

- **Bot deep-link linking.** Customer clicks "Link Telegram" on their osTicket profile → opens `https://t.me/<your-bot>?start=<token>` → bot replies "Linked!" within a second. No manual chat_id pasting.
- **Manual fallback.** Customers (or admins, on their behalf) can still paste a `chat_id` into a custom user-form field. Works without configuring a webhook.
- **Inline keyboards on every message** (optional). "View ticket" button for customers; "View ticket" + "Reply" for admins. Configurable labels.
- **Per-event × per-audience matrix.** Eight independent toggles:
  - Ticket created → customer / admin
  - Customer reply → admin
  - Staff reply → customer / admin
  - Status changed → customer / admin
  - Assignment changed → admin
- **Three parse modes:** MarkdownV2 (recommended, automatic escaping of variable values), HTML, or plain text.
- **Customer opt-in** via a `telegram_opt_in` checkbox on the Contact Information form.
- **HTTP retries** on 429 / 5xx / network errors with exponential backoff. Honors Telegram's `parameters.retry_after` and HTTP `Retry-After`.
- **Webhook endpoint** processes `/start`, `/unlink`, `/status`. Secured with the `X-Telegram-Bot-Api-Secret-Token` header.
- **Credentials masked in UI** (`PasswordField` for bot token, webhook secret, Sentry DSN).
- **PII redaction in logs** (chat IDs partially masked, message bodies truncated, secrets redacted).
- **Optional Sentry integration** via the same lightweight envelope client as the Evolution plugin (no Composer required).

---

## Quick start

### 1. Create a Telegram bot

In Telegram, message **@BotFather**:

```
/newbot
<name your bot>
<choose a username ending in "bot">
```

BotFather replies with a bot token. Copy it. Also note the **bot username** (without the `@`).

### 2. Drop the plugin into osTicket

```bash
git clone --branch v0.1.0 --depth 1 https://github.com/RenatoAscencio/osticket-telegram-bot.git
rsync -av osticket-telegram-bot/plugin/ /path/to/osticket/include/plugins/telegram-bot/
```

### 3. Install + configure

In osTicket admin: **Manage → Plugins → Add New Plugin** → *Telegram Bot Notifications* → **Install** → click into it.

Required:

| Section | Field | Value |
| ------- | ----- | ----- |
| Bot — Connection | Bot token | from BotFather |
| Bot — Connection | Bot username | without `@`, e.g. `MyCompanySupportBot` |
| Recipients | Admin Telegram chat IDs | one per line; get with [@userinfobot](https://t.me/userinfobot) |
| Misc | osTicket base URL | `https://your-osticket.example.com` |

For webhook + linking flow (recommended):

| Section | Field | Value |
| ------- | ----- | ----- |
| Webhook | Public webhook URL | `https://your-osticket/include/plugins/telegram-bot/webhook.php` |
| Webhook | Webhook secret token | generate: `openssl rand -hex 24` |

Then **Enable** the plugin and set the webhook on Telegram's side (see [docs/webhook-setup.md](./docs/webhook-setup.md)).

---

## How it works

```
   osTicket signal                            Telegram update (inbound)
        │                                            │
        ▼                                            ▼
  TelegramBotNotificationsPlugin           plugin/webhook.php
   ├─ signal dispatch                       │ verifies X-Telegram-Bot-Api-Secret-Token
   ├─ template rendering (MD2/HTML/plain)   │ dispatches to processUpdate()
   ├─ inline keyboard build                 ▼
   ├─ client opt-in check               /start <token>  → consume token + link user
   ├─ chat_id resolution                /unlink         → remove mapping
   │   ├─ deep-link store first         /status         → show linked user
   │   └─ manual field fallback
   ▼
  TelegramBotClient.sendMessage  ──► api.telegram.org
   (retries on 429/5xx, honors Retry-After,
    exponential backoff)
```

See [docs/architecture.md](./docs/architecture.md) for the full module map and failure-mode table.

---

## Linking customers' accounts

Two ways, both can be enabled simultaneously:

### A — Bot deep-link (recommended)

1. Customer clicks **"Link Telegram"** on their osTicket profile.
2. Browser opens `https://t.me/<bot>?start=<one-shot-token>`.
3. Customer hits **Start** in Telegram; the bot pre-fills `/start <token>`.
4. Bot replies `✅ Linked!`; the plugin saves the `(user_id, chat_id)` mapping.

Tokens are 32-char hex, TTL 15 min (configurable), one-shot.

### B — Manual chat_id paste

1. Admin adds a custom field `telegram_chat_id` to the Contact Information form.
2. Customer (or admin) pastes their numeric chat_id there.

Full walkthrough including troubleshooting: [docs/user-linking.md](./docs/user-linking.md).

---

## Inline buttons

Each outbound message can include an inline keyboard. Defaults:

| Audience | Buttons |
| -------- | ------- |
| Customer | 🎟 View ticket |
| Admin    | 🎟 View ticket · 💬 Reply |

Labels and visibility are configurable. Toggle the whole keyboard off if you don't want clickable buttons. See [docs/inline-buttons.md](./docs/inline-buttons.md).

---

## Project layout

```
osticket-telegram-bot/
├── plugin/
│   ├── plugin.php
│   ├── config.php
│   ├── telegram.php           Main class — signal handlers, dispatcher, link flow
│   ├── webhook.php            Public endpoint for Telegram updates
│   └── lib/
│       ├── TelegramBotClient.php
│       ├── TelegramFormatter.php
│       ├── InlineKeyboardBuilder.php
│       ├── UserLinkStore.php
│       ├── SentryReporter.php
│       └── LogRedactor.php
├── docs/                      architecture, user-linking, webhook-setup, inline-buttons, …
├── docker/                    osTicket 1.18.3 + MariaDB local stack
├── scripts/                   deploy.sh + .env.example
├── tests/                     5 test files, 81 assertions, no PHPUnit required
└── .github/                   CI workflow + issue/PR templates
```

---

## Roadmap

- [ ] Callback-button actions (close ticket from Telegram, accept assignment, etc.)
- [ ] Long-polling fallback (cron-driven `getUpdates`) for installs that can't expose a public webhook
- [ ] Per-staff direct DM on assignment
- [ ] Media (photo / file) notifications for attachments
- [ ] PR to `osTicket/osTicket-plugins` once feature-stable

---

## Contributing

PRs welcome — see [CONTRIBUTING.md](./CONTRIBUTING.md). The short version: keep code style consistent with `osTicket/osTicket-plugins`, write a test for any new `lib/` logic, and update both READMEs (en + es) for user-visible options.

---

## License

[GPL-2.0-or-later](./LICENSE). Compatible with osTicket itself.
