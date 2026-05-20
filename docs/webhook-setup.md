# Webhook setup

Telegram delivers inbound updates (when a user runs `/start`, `/unlink`, etc.) by POSTing to a URL you register. This document walks through:

1. Choosing whether you actually need the webhook.
2. Generating a secret token.
3. Registering the webhook with Telegram.
4. Verifying it.
5. Tearing it down.

---

## 1. Do you need a webhook?

You only need a webhook if you want **bot deep-link linking** (the customer clicks "Link Telegram" on their profile and the bot replies "Linked!").

If you're fine with **manual chat_id entry** (customer pastes their chat_id into a form field), you can skip the webhook entirely and disable the `Enable bot deep-link linking` toggle in plugin config.

---

## 2. Generate a secret token

The secret token is sent by Telegram in every webhook POST as the
`X-Telegram-Bot-Api-Secret-Token` header. The plugin verifies this header
before processing the update. If they don't match, the request is
rejected with HTTP 401.

Generate a strong one:

```bash
openssl rand -hex 24
```

Paste it into the plugin config field **"Webhook secret token"**.

You **must** set a secret in production. The plugin tolerates an empty
secret for dev convenience but logs a warning on every request.

---

## 3. Register the webhook with Telegram

Three ways, pick one:

### A — Via the local slash command (recommended for prod)

If you have the `.claude/commands/tg-set-webhook.md` slash command installed locally:

```
/tg-set-webhook
```

It will read `webhook_public_url` and `webhook_secret_token` from the plugin config on the remote and call `setWebhook` accordingly.

### B — Via `curl` from the osTicket server

```bash
ssh <your-server> '
TOKEN="<your-bot-token>"
URL="https://<your-osticket>/include/plugins/telegram-bot/webhook.php"
SECRET="<your-webhook-secret>"
curl -sS -X POST "https://api.telegram.org/bot$TOKEN/setWebhook" \
  -F url="$URL" \
  -F secret_token="$SECRET" \
  -F allowed_updates="[\"message\"]" \
  -F drop_pending_updates=true
'
```

Expect `{"ok":true,"result":true,"description":"Webhook was set"}`.

### C — Programmatic, from PHP (advanced)

```php
$plugin->applyWebhookFromConfig();
```

Returns the same envelope the bot client returns elsewhere.

---

## 4. Verify the webhook

```bash
curl -s "https://api.telegram.org/bot<TOKEN>/getWebhookInfo" | jq .
```

You should see:

```json
{
  "ok": true,
  "result": {
    "url": "https://your-osticket/include/plugins/telegram-bot/webhook.php",
    "has_custom_certificate": false,
    "pending_update_count": 0,
    "max_connections": 40,
    "allowed_updates": ["message"]
  }
}
```

Key fields:

- `url` matches your configured value.
- `pending_update_count` should be near 0 in steady state — if it's growing, the webhook is failing (Telegram retries).
- `last_error_message` (when present) tells you why Telegram is failing.

---

## 5. Smoke test

In Telegram, send `/start` (without an argument) to your bot. The bot should reply:

> Hi! Use the "Link Telegram" button on your osTicket profile to connect your account.

If it doesn't:

1. Tail the PHP error log on the server, filter for `TelegramBot/webhook`.
2. Check `getWebhookInfo` for a `last_error_message`.
3. Verify the secret token matches (most common cause of 401).

---

## 6. Tear down

```bash
curl -sS "https://api.telegram.org/bot<TOKEN>/deleteWebhook?drop_pending_updates=true"
```

After deletion, the deep-link linking flow no longer works. The plugin can still send outbound notifications (it never required a webhook for that).

---

## Common failure modes

| Symptom | Likely cause | Fix |
| ------- | ------------ | --- |
| Telegram returns `Bad webhook: HTTP code 401` | secret mismatch | Re-paste secret in plugin config + re-run setWebhook |
| `Bad webhook: SSL error` | self-signed cert or expired | Use a real cert (Let's Encrypt, Cloudflare) — Telegram is strict |
| `Bad webhook: connection refused` | path wrong / file not deployed | `curl -I https://.../webhook.php` from outside the network |
| Plugin replies but updates not delivered | webhook silently working but no plugin row in `<prefix>plugin` | Make sure plugin is installed AND enabled |
| Linking token "invalid or expired" message | token TTL elapsed (default 15 min) or already used | Generate a fresh one via the profile button |

---

## Local development without a public URL

Telegram requires HTTPS for webhook URLs. For local dev:

- Use a tunnel: `ngrok http 8082` then point the webhook at the ngrok HTTPS URL.
- Or run the linking flow manually: copy a token from `<prefix>telegram_link_tokens`, send `/start <token>` to the bot in Telegram, then manually insert the row into `<prefix>telegram_links`.
- Or just rely on the manual `chat_id` field for testing — works without a webhook.
