# Local Docker test stack

A one-command osTicket + MariaDB lab to develop and test the plugin without touching production.

## Start

```bash
docker compose up -d --build
```

| Service | URL / Connection |
| ------- | ---------------- |
| osTicket (customer view) | http://localhost:8082/ |
| osTicket (staff/admin) | http://localhost:8082/scp/ |
| MariaDB | host `db`, db `osticket`, user `osticket`, pass `osticket` |

The plugin source folder (`../plugin/`) is **bind-mounted read-only** into `/var/www/html/include/plugins/telegram-bot` inside the osTicket container. Editing files on your host shows up live in osTicket — no rebuild needed.

## First-run installer

On first boot the osTicket web installer is exposed at `http://localhost:8082/setup/`. Fill in:

| Field | Value |
| ----- | ----- |
| System URL | `http://localhost:8082/` |
| Admin user, password, email | anything you'll remember |
| MySQL Host | `db` |
| MySQL Database | `osticket` |
| MySQL Username | `osticket` |
| MySQL Password | `osticket` |
| Table Prefix | `ost_` |

After the installer finishes:

```bash
# Inside the running container:
docker compose exec osticket sh -lc 'rm -rf /var/www/html/setup && chmod 644 /var/www/html/include/ost-config.php'
```

## Install the plugin

1. Browse to `http://localhost:8082/scp/` → log in.
2. **Admin Panel → Manage → Plugins → Add New Plugin** → *Telegram Bot Notifications* → **Install**.
3. Click into the plugin → fill in:
   - **Base URL** of your Evolution API
   - **Instance** name
   - **API key**
   - **Default country code** (e.g. `52`)
   - **Admin numbers** (one per line)
   - **osTicket base URL:** `http://localhost:8082`
4. **Enable**.

## Smoke test

Create a ticket at `http://localhost:8082/` using a phone number that has WhatsApp. The associated WhatsApp should receive a "ticket created" message; configured admin numbers should also receive one.

If nothing arrives:

```bash
docker compose logs -f osticket | grep -i TelegramBot
```

…shows the plugin's debug/error trail when **Verbose logging** is on.

## Reset everything

```bash
docker compose down -v
```

Deletes the DB volume and attachments. Next `up -d` reruns the installer.

## Notes

- The container uses PHP 8.1 (same as the production CLI on `mx4`). To test against PHP 8.3 (matching the production web), change `FROM php:8.1-apache` to `FROM php:8.3-apache` in `Dockerfile` and rebuild.
- Evolution API itself is **not** part of this stack — you point the plugin at your own instance. If you need a fully local Evolution API for offline testing, follow the [Evolution API docker docs](https://doc.telegram-bot.com/v2/installation/docker).
