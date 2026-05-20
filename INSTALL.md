# Installation guide

This document covers three install scenarios:

1. **Local Docker** — fastest path to experiment with the plugin.
2. **Existing osTicket install** — drop the plugin into a real install.
3. **Production deploy via SSH + rsync** — the supported script-based path.

---

## 1. Local Docker test stack

```bash
git clone https://github.com/RenatoAscencio/osticket-telegram-bot.git
cd osticket-telegram-bot/docker
docker compose up -d --build
```

Browse to `http://localhost:8082` and run the osTicket installer (DB host `db`, DB/user/pass all `osticket`, prefix `ost_`). Then **Manage → Plugins → Add New Plugin** → *Telegram Bot Notifications* → **Install**.

The plugin source is bind-mounted into the container — edits on the host are picked up live.

See [docker/README.md](./docker/README.md) for details.

---

## 2. Drop into an existing osTicket install

```bash
# From the repo root:
rsync -av --exclude '.git' plugin/ /path/to/osticket/include/plugins/telegram-bot/
chown -R <web-user>:<web-group> /path/to/osticket/include/plugins/telegram-bot
```

In osTicket: **Manage → Plugins → Add New Plugin → Install** → fill credentials → **Enable**.

For the webhook + linking flow:

1. Confirm the file is reachable: `curl -I https://<your-osticket>/include/plugins/telegram-bot/webhook.php` should return 200 (or 400 — both are fine; 404 means rsync didn't land it correctly).
2. Set the webhook on Telegram: see [docs/webhook-setup.md](./docs/webhook-setup.md).

---

## 3. Production deploy via SSH + rsync

```bash
cp scripts/.env.example scripts/.env
$EDITOR scripts/.env                   # Fill in REMOTE, REMOTE_PLUGIN_DIR, etc.
source scripts/.env && ./scripts/deploy.sh --dry-run
source scripts/.env && ./scripts/deploy.sh
```

What the script does:

1. Backs up the DB on the remote (if `REMOTE_DB` is set).
2. rsyncs `plugin/` into `REMOTE_PLUGIN_DIR/`.
3. Chowns the deployed files to `REMOTE_USER_GROUP` (if set).
4. Prints the next manual step.

`scripts/.env` is gitignored.

Step-by-step in [docs/deploy-production.md](./docs/deploy-production.md).

---

## Verifying

1. **Token check.** From a server shell:
   ```bash
   curl -sH "Content-Type: application/json" \
        "https://api.telegram.org/bot<TOKEN>/getMe"
   ```
   Expect `{"ok":true,"result":{...,"username":"YourBot"}}`.

2. **Webhook check.**
   ```bash
   curl -s "https://api.telegram.org/bot<TOKEN>/getWebhookInfo"
   ```
   The `url` field should match your `webhook_public_url`.

3. **Smoke test.** Open Telegram, send `/start` to your bot — you should get a greeting. Create an osTicket ticket and watch the admin chat ID(s) receive a notification.

4. **Logs.** With **Verbose logging** on, tail the PHP error log:
   ```bash
   ssh <REMOTE> 'tail -F <path-to-php-error-log> | grep TelegramBotNotifications'
   ```
