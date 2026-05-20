# Architecture

## Goals

Same as the sibling Evolution plugin:

1. **Safe by default.** Never crash osTicket. If Telegram is down, log + Sentry (optional), don't throw into the request thread.
2. **Drop-in deployable.** No Composer; copy files into `include/plugins/telegram-bot/`.
3. **Testable in isolation.** Pure logic (formatter, keyboard builder, redactor) is in `plugin/lib/` and exercised by `tests/` without osTicket loaded.
4. **Forward-compatible with upstream PR.**

## Module map

| File | Responsibility |
| ---- | -------------- |
| `telegram.php` | Wires signals, orchestrates dispatch, builds template variables, handles inbound webhook updates. |
| `config.php` | UI fields + validation. No business logic. |
| `webhook.php` | Public inbound endpoint. Verifies secret token, parses JSON, delegates to `TelegramBotNotificationsPlugin::processUpdate()`. |
| `lib/TelegramBotClient.php` | Pure HTTP to Bot API. Returns uniform `{ok, status, body, error}`. Retries on 429/5xx/network errors with exponential backoff + `Retry-After`. |
| `lib/TelegramFormatter.php` | Pure: `{{var}}` substitution, MarkdownV2/HTML escaping for variable values, WYSIWYG-HTML → MarkdownV2/HTML conversion, UTF-8 truncation. |
| `lib/InlineKeyboardBuilder.php` | Pure builder for `inline_keyboard` markups. Enforces Telegram limits (8/row, 100 total, 64-byte callback_data). |
| `lib/UserLinkStore.php` | DB persistence for the user↔chat mapping and one-shot linking tokens. Auto-creates tables on first use. |
| `lib/SentryReporter.php` | Optional. Parses DSN, POSTs envelopes. No-op when DSN blank. |
| `lib/LogRedactor.php` | Pure: walks log-context arrays, masks chat_ids and phones, truncates message bodies, redacts secrets including `bot_token` / `webhook_secret_token`. |

## Outbound flow

```
osTicket signal (ticket.created / threadentry.created / model.updated)
      │
      ▼
TelegramBotNotificationsPlugin
   ├─ markOnce(ticketId, kind)                     dedup model.updated
   ├─ ticketVars(ticket)                           subject, name, status, ...
   ├─ escapeVarsForParseMode(vars)                 MD2/HTML auto-escape
   ├─ render(template, escapedVars)
   ▼
sendToClient(ticket, template, vars):
   ├─ userOptedIn(ticket)                          opt-out short-circuits HERE
   ├─ resolveClientChatId(ticket)                  deep-link store → manual field
   ├─ buildKeyboard(ticket, forAdmin=false)
   └─ dispatchSend(chatId, text, kb)               → TelegramBotClient.sendMessage

sendToAdmins(template, vars, kb):
   ├─ for each chat_id in admin_chat_ids:
   │    ├─ optional usleep(send_delay_ms)
   │    └─ dispatchSend(chatId, text, kb)
```

## Inbound flow (webhook)

```
Telegram POST /webhook.php
      │
      ▼ verify X-Telegram-Bot-Api-Secret-Token (hash_equals)
parse JSON body → $update
      │
      ▼ TelegramBotNotificationsPlugin::processUpdate($update)
parseCommand($update.message.text)
      │
      ├─ /start <token>  → links.consumeToken → links.link(user_id, chat_id) → bot reply
      ├─ /unlink         → links.unlinkByChat → bot reply
      ├─ /status         → bot reply
      └─ (anything else) → silent
```

## Failure modes

| Scenario | Behavior |
| -------- | -------- |
| Telegram API unreachable / transient 5xx / 429 | `TelegramBotClient::call()` retries up to `http_max_attempts` (default 3) with exponential backoff. Honors `Retry-After` (HTTP header) and `parameters.retry_after` (Telegram-style, in body). If all attempts fail, dispatcher logs error + Sentry. The osTicket request itself does not fail. |
| Customer opted out via profile field | `sendToClient()` returns early before any chat-id resolution or API call. Logged at `info`. Admin sends are unaffected. |
| Customer not linked / chat_id unknown | `sendToClient()` logs at debug and returns. Admin sends are unaffected. |
| Webhook secret mismatch | `webhook.php` returns 401, logs the rejection, does not invoke the plugin. |
| Webhook payload malformed JSON | `webhook.php` returns 200 (avoiding Telegram retry loop), logs at error_log, skips. |
| Unknown `/command` from a linked user | Silently ignored. |
| Two consecutive `model.updated` for same ticket | Per-`(ticket, change-kind)` dedup ensures status + assignment fire once each per request. |
| Sentry DSN invalid | Reporter silently no-ops. |
| Plugin tables can't be created (DB perms) | Linking flow returns null; manual chat_id fallback still works. |

## Why a minimal Sentry client?

Same reason as the Evolution plugin — keep `composer require` out of the install path. Replace with the official `sentry/sentry` SDK if you need richer features (breadcrumbs, performance, sessions).
