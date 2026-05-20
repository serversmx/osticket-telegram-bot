# Configuration reference

Every field shown in **Manage → Plugins → Telegram Bot Notifications → Configure**.

## Bot — Connection

| Field | Required | Notes |
| ----- | -------- | ----- |
| **Bot token** | yes | From @BotFather. Masked after save. Format: `<digits>:<base64ish>`. |
| **Bot username** | yes | Without the `@`. Used to build `t.me/<bot>?start=...` linking URLs. |
| **Bot API base URL** | no | Defaults to `https://api.telegram.org`. Override only for a local Bot API server. |
| **Verify SSL certificate** | — | Recommended ON. |
| **HTTP timeout (seconds)** | — | Per-request total timeout. Default 15. Minimum effective value 3. |
| **Max HTTP attempts** | — | Default 3. 1 disables retries. Backs off exponentially; honors `Retry-After` and `parameters.retry_after`. |

## Recipients — master switches

| Field | Notes |
| ----- | ----- |
| **Notify customers** | Master kill-switch. When off, customers never get a notification regardless of per-event settings. |
| **Notify staff/admins** | Master kill-switch for admin notifications. |
| **Admin Telegram chat IDs** | One per line. Signed integers (4–20 digits). Get with @userinfobot. Examples: `123456789` (private user), `-1001234567890` (supergroup/channel). |

## Customer opt-in & linking

| Field | Notes |
| ----- | ----- |
| **Enable bot deep-link linking** | Default ON. Requires the webhook to be configured (see below) to actually function. |
| **Allow manual chat_id entry** | Default ON. Reads `chat_id` from a custom user-form field. Works without a webhook. |
| **Manual chat_id field variable name** | Default `telegram_chat_id`. Must match the field variable name you create in Manage → Forms → Contact Information. |
| **Respect customer opt-in preference** | Default ON. Look up an opt-in checkbox on the user profile before sending. |
| **Opt-in field variable name** | Default `telegram_opt_in`. |
| **Default to opt-IN when field is absent** | Default ON. Backwards-compat for installs that don't have the field yet. |
| **Linking token TTL (seconds)** | Default 900 (15 min). |

## Webhook

| Field | Notes |
| ----- | ----- |
| **Public webhook URL** | Full HTTPS URL to `webhook.php`. Required for deep-link linking. |
| **Webhook secret token** | Sent by Telegram as `X-Telegram-Bot-Api-Secret-Token`. Generate with `openssl rand -hex 24`. Masked after save. |

## Events — per-event-per-audience matrix

| Event | Default client | Default admin |
| ----- | -------------- | ------------- |
| Ticket created | ON | ON |
| Customer reply | n/a (no client) | ON |
| Staff reply | ON | OFF |
| Status changed | ON | OFF |
| Assignment changed | n/a (no client) | OFF |

## Message formatting

| Field | Notes |
| ----- | ----- |
| **Parse mode** | `MarkdownV2` (recommended), `HTML`, or plain. Variable values are auto-escaped for the chosen mode. |
| **Disable URL previews** | Default ON. Suppresses Telegram's link-preview cards. |
| **Silent send (no sound)** | Default OFF. When ON, messages arrive without notification sound. |

## Templates

Placeholders available: `{{ticket_number}} {{subject}} {{name}} {{email}} {{department}} {{priority}} {{status}} {{assignee}} {{poster_type}} {{message}} {{ticket_link}}`. Use `{{var|fallback}}` for defaults.

Eight templates (client/admin × event types) — see plugin config screen for the full set.

## Inline buttons

| Field | Notes |
| ----- | ----- |
| **Show "View ticket" button** | Default ON. URL button → ticket page. |
| **"View ticket" button label** | Default `🎟 View ticket`. |
| **Show "Reply" button (admins only)** | Default ON. Only attached to admin messages. |
| **"Reply" button label** | Default `💬 Reply`. |

## Misc

| Field | Notes |
| ----- | ----- |
| **osTicket base URL** | Required when `{{ticket_link}}` or inline buttons are used. |
| **Delay between admin sends (ms)** | Pacing between consecutive admin sends. Telegram allows ~30 msg/s to different chats, 1 msg/s to the same chat — use 100–1000 ms when fanning out to many admins. |

## Sentry (optional)

| Field | Notes |
| ----- | ----- |
| **Sentry DSN** | Format: `https://<key>@<host>/<project_id>`. Masked after save. Leave blank to disable. |
| **Sentry environment** | Default `production`. Free-form. |
| **Also capture global PHP errors** | Default OFF. Turn ON only after plugin-scoped reporting works. |

## Debug

| Field | Notes |
| ----- | ----- |
| **Verbose logging** | Default OFF. When ON, debug + info lines (with PII redaction) go to the PHP error log. Errors/warnings always log. |
