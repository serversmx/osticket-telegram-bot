# Security Policy

## Supported versions

This project is pre-1.0 and not yet stable. Security fixes are applied to
the `main` branch only.

## Reporting a vulnerability

**Do not** open a public GitHub issue for security problems.

Email a description to: `5641902+RenatoAscencio@users.noreply.github.com`
(GitHub will route to the project owner).

Expect acknowledgement within 5 business days and a triage decision within 10.

## Threat model

The plugin runs **inside an osTicket admin context** and makes **outbound
HTTPS** calls to:

1. **Telegram Bot API** (`api.telegram.org` by default) — to send messages,
   verify the bot token, set the webhook, and read updates.
2. **Sentry** (optional, only if a DSN is configured) — for error reports.

It also exposes ONE **inbound public endpoint** (`webhook.php`) that
Telegram POSTs to when users send `/start`, `/unlink`, or `/status`. This
endpoint is the most security-sensitive part of the plugin.

It reads from / writes to the osTicket database:

- **Reads:** the ticket and user the signal fired on, the plugin's own
  configuration rows.
- **Writes:** two plugin-owned tables (`<prefix>telegram_links` and
  `<prefix>telegram_link_tokens`), and consumes one-shot tokens.

## Trust boundaries

| Boundary | Trust |
| -------- | ----- |
| osTicket admin user | **Fully trusted.** Can set bot token, webhook URL, admin chat IDs, Sentry DSN. SSRF / data exfiltration via configuration is accepted as the cost of admin authority. |
| osTicket end users | Untrusted. Their input flows through chat-ID validation and template rendering with auto-escape for MarkdownV2/HTML; never executes. |
| Telegram | Trusted ingest; we treat its updates as untrusted-but-authentic AFTER verifying the secret token. The plugin only acts on `/start`, `/unlink`, `/status` commands. |
| Public internet to `webhook.php` | Untrusted. Anyone can POST. Defended by `X-Telegram-Bot-Api-Secret-Token` verification + JSON-only parsing + ignore-on-malformed. |
| Sentry endpoint | Trusted ingest. POST + ignore response. |
| Other tenants on shared hosting | Untrusted. PHP error log mitigated by PII redaction (see below). |

## Security controls

### Webhook endpoint hardening

- **Secret token verification.** `webhook.php` rejects requests whose
  `X-Telegram-Bot-Api-Secret-Token` header doesn't match the configured
  value. Uses `hash_equals()` (timing-safe).
- **Strict JSON.** Body parsed with `json_decode($_, true)`. Non-array
  results are silently ignored (HTTP 200 to avoid Telegram retry loops).
- **Always 200 on success path.** Even when the JSON looks malformed —
  Telegram retries non-2xx responses, which would flood the log.
- **No HTML output.** Response body is just `ok` — no user-influenced
  content is reflected.
- **No DB writes on unknown commands.** Only `/start <token>`, `/unlink`,
  `/status` mutate state. Unknown commands are silently dropped.

### Linking tokens

- 32-character hex (16 bytes of `random_bytes()`).
- One-shot: consumed on the first `/start <token>` regardless of success
  or expiry — prevents replay.
- TTL configurable, default 900 seconds (15 min).
- Stored in `<prefix>telegram_link_tokens` keyed by token; pruning helper
  available for cron.

### Credentials handling

- `bot_token` and `webhook_secret_token` are `PasswordField` (masked in UI).
- Sentry DSN is `PasswordField`.
- Bot token is NEVER logged. Only the result envelope status code reaches
  the log; the URL path containing the token is never echoed.

### Input validation

- Admin chat IDs validated server-side (signed integer, 4–20 digits).
- Bot username regex-validated (3–32 chars, `[A-Za-z0-9_]`).
- Webhook URL forced to HTTPS at save time (Telegram refuses plain HTTP).
- Bot token regex-validated (`\d+:[A-Za-z0-9_-]+`).

### SQL injection

- The two plugin-owned tables use cast-to-int for chat_id / user_id and
  `db_real_escape()` for token strings. No user-supplied SQL fragments.

### PII handling in logs

- `EvoLogRedactor::context()` redacts:
  - `chat_id` / `chat_ids` → last-4-digits, e.g. `******7890`
  - `text` / `message` / `body` → `[N chars] preview…` (first 40 chars)
  - `bot_token` / `secret_token` / `webhook_secret_token` / `authorization` / `apikey` / `api_key` / `token` → `[REDACTED]`
- Applies to every log level, so even verbose logging never leaks full
  chat IDs or message contents.

### Outbound HTTPS

- SSL verification on by default. Admins can disable it but the UI warns
  against doing so for production.
- Connect timeout 10s; total timeout configurable (3–N seconds, default 15).
- All cURL calls include `Accept: application/json`.
- Retries 429 / 5xx / network errors up to N times with exponential backoff
  (capped at 4s).

### Error handling

- Every signal handler wraps its body in `try/catch`. Exceptions never
  bubble up to the osTicket request.
- When Sentry capture-global is enabled, a global exception handler reports
  to Sentry and re-throws — it does not swallow.

## Accepted risks

| # | Risk | Why accepted |
|---|------|--------------|
| 1 | Admin can point webhook to a hostile collaborator's URL via setWebhook — but the URL is sent only to Telegram, not invoked by us. | Admin trust assumption (same as Evolution plugin). |
| 2 | If `webhook_secret_token` is not configured, any HTTP client knowing the URL can POST updates. Mitigation: UI strongly recommends setting one + logs a warning when missing. | Backwards-compat with simple setups. |
| 3 | If `Verify SSL` is off, MITM possible between the host and api.telegram.org. | Opt-in dev convenience. |
| 4 | A malicious bot operator (someone who gains the bot token) can impersonate the bot in your channels. | Token rotation via BotFather is a 30-second operation. |

## Hardening recommendations for operators

- Set a long `webhook_secret_token` (32+ random characters).
- Rotate the bot token periodically (BotFather → `/revoke`).
- Restrict outbound network access from the osTicket host to only
  `api.telegram.org` and your Sentry host (egress firewall).
- Disable manual chat_id entry once all customers are migrated to the
  deep-link flow — manual entry has no automatic verification that the
  customer actually owns the chat ID.
- Periodically prune expired link tokens (`pruneExpiredTokens()`).

## Out of scope

- Vulnerabilities in osTicket core (report to https://github.com/osTicket/osTicket).
- Vulnerabilities in Telegram Bot API itself.
