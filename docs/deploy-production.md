# Production deploy

Step-by-step for deploying the plugin to a production osTicket via SSH + rsync.

## Prereqs

- SSH access to the production server, configured as an alias in `~/.ssh/config`.
- Sudo (or root) on the remote, for the `chown` step.
- A Telegram bot created via @BotFather.
- (Optional) A Sentry project DSN.

## 1. Configure your local env file

```bash
cp scripts/.env.example scripts/.env
$EDITOR scripts/.env
```

Fill in:

| Variable | Example | Required? |
| -------- | ------- | --------- |
| `REMOTE` | `your-server` (an SSH alias from `~/.ssh/config`) | yes |
| `REMOTE_PLUGIN_DIR` | `/var/www/osticket/include/plugins/evolution-api` | yes |
| `REMOTE_USER_GROUP` | `www-data:www-data` | no — skips chown if empty |
| `REMOTE_DB` | `osticket` | no — skips backup if empty |
| `REMOTE_BACKUP_DIR` | `/var/backups/osticket` | no — defaults to `/tmp` |
| `REMOTE_OSTICKET_URL` | `https://support.example.com` | no — only used in the post-deploy hint |

This file is gitignored — your specific server details never end up in version control.

## 2. Dry run first

```bash
source scripts/.env && ./scripts/deploy.sh --dry-run
```

Verify the rsync output lists what you expect.

## 3. Real deploy

```bash
source scripts/.env && ./scripts/deploy.sh
```

The script will, in order:

1. **Backup the DB** to `${REMOTE_BACKUP_DIR}/${REMOTE_DB}-pre-tgbot-<ts>.sql.gz` (skipped if `REMOTE_DB` is unset).
2. **rsync** `plugin/` into `${REMOTE_PLUGIN_DIR}/`.
3. **chown** the deployed files to `${REMOTE_USER_GROUP}` (skipped if unset).
4. Print the next manual steps.

## 4. Register & configure in osTicket

1. Open `${REMOTE_OSTICKET_URL}/scp/`.
2. **Admin Panel → Manage → Plugins → Add New Plugin**.
3. Find *Telegram Bot Notifications* → **Install**.
4. Click into the plugin → fill in:
   - Bot: token (from @BotFather), bot username (without `@`)
   - Admin Telegram chat IDs (one per line, signed integers)
   - osTicket base URL
   - Webhook public URL + secret token (recommended for deep-link linking)
   - (Optional) Sentry DSN
5. **Enable** the plugin.

## 5. Verify

### Connection check from the server

```bash
ssh "$REMOTE" 'curl -s -H "apikey: <YOUR_KEY>" \
  https://<YOUR_EVO_HOST>/instance/connectionState/<INSTANCE>'
```

Expect `"state":"open"` somewhere in the JSON.

### End-to-end smoke test

Create a test ticket via your osTicket public URL using a phone number that has WhatsApp. Within a few seconds:

- The customer's phone should receive a "ticket created" WhatsApp message.
- Each configured admin number should also receive one.

### Tail logs

With **Verbose logging** enabled in the plugin config, the PHP error log on the remote will show one line per request hop. The exact path depends on your stack:

| Stack | Typical log path |
| ----- | ---------------- |
| LiteSpeed | `/usr/local/lsws/logs/error.log` |
| Apache (Debian/Ubuntu) | `/var/log/apache2/error.log` |
| Apache (RHEL/CentOS) | `/var/log/httpd/error_log` |
| Nginx + PHP-FPM | `/var/log/php*-fpm.log` or `/var/log/nginx/error.log` |

```bash
ssh "$REMOTE" 'tail -F <path-to-error-log> | grep TelegramBotNotifications'
```

## 6. Cache table verification

The first send creates `<prefix>telegram_links` automatically. Verify:

```bash
ssh "$REMOTE" 'mysql <your-db> -e "DESCRIBE <prefix>telegram_links; SELECT COUNT(*) AS rows_ FROM <prefix>telegram_links;"'
```

Replace `<prefix>` with your osTicket `TABLE_PREFIX` (typically `ost_`).

## 7. Rollback

The plugin does **not** modify osTicket core tables. To roll back:

```bash
# 1. Disable in admin UI (Plugins → Telegram Bot → Disable)
# 2. Remove files:
ssh "$REMOTE" 'rm -rf <REMOTE_PLUGIN_DIR>'
# 3. Uninstall from admin UI (Plugins → Telegram Bot → Uninstall)
# 4. Optionally drop the cache table:
ssh "$REMOTE" 'mysql <your-db> -e "DROP TABLE IF EXISTS <prefix>telegram_links"'
```

The DB backup from step 3 (if you enabled it) is your safety net.

## 8. CDN cache invalidation (optional)

If you front osTicket with a CDN (Cloudflare, Fastly, etc.) and you edit any plugin file that affects served pages, purge the relevant URLs from the CDN dashboard or via API. The plugin itself does not serve any cached CDN content — this only matters if you edit osTicket templates while iterating.
