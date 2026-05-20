# Staff profile patch — "Gestionar Telegram" button

This patch adds a prominent button at the top of every staff member's
**My Profile** page (`/scp/profile.php`) that links to
`/scp/link-telegram.php`. Without it, staff have to know the URL.

## Why it's a core-file modification

osTicket 1.18 does **not** expose a plugin extension point for injecting
UI into the staff profile page. The available hooks are:

- `Plugin::bootstrap()` — runs **before** the global `$ost` is set,
  so calling `$ost->addExtraHeader()` from there is a no-op.
- `Signal::connect('apps.scp', $dispatcher)` — lets plugins register URL
  routes under `/scp/apps/...` but does not add a visible nav entry.
- No signals fire at template-render time.

The pragmatic solution is to patch `include/staff/profile.inc.php` once.
The plugin ships `scripts/apply-staff-profile-patch.sh` which does this
**idempotently, with backup, with `php -l` lint, and with restore-on-fail**.

## Applying the patch

From your local workspace:

```bash
ssh <your-server> 'bash -s' < scripts/apply-staff-profile-patch.sh
```

Or copy it and run:

```bash
scp scripts/apply-staff-profile-patch.sh <your-server>:/tmp/
ssh <your-server> 'bash /tmp/apply-staff-profile-patch.sh'
```

Env vars you can override:

| Variable | Default | Notes |
| -------- | ------- | ----- |
| `OSTICKET_PROFILE` | `/var/www/osticket/include/staff/profile.inc.php` | Path to profile.inc.php on the remote |
| `PHP_CLI` | `php` (whatever is on PATH) | PHP CLI binary for the lint step. Override for cPanel installs, e.g. `/opt/cpanel/ea-php83/root/usr/bin/php` |
| `WEB_OWNER` | `www-data:www-data` | `user:group` to chown the file to. Override for cPanel installs to match your cPanel account name |

## What the patch does

Inserts this block right after the opening `<form action="profile.php" ...>`
tag in `include/staff/profile.inc.php`:

```html
<!-- BEGIN telegram-bot plugin patch -->
<div id="tg-bot-link-box" style="…flex layout, blue background…">
  <div>
    <strong>📲 Notificaciones por Telegram</strong>
    <div>Vincula tu cuenta de Telegram con un clic — recibe alertas de tickets directamente en tu chat.</div>
  </div>
  <a href="link-telegram.php" class="button" style="background:#2563eb;…">🔗 Gestionar Telegram</a>
</div>
<!-- END telegram-bot plugin patch -->
```

The link points at `link-telegram.php` (relative to `/scp/`), which is
the staff-facing page from `link-telegram-staff.php.example`.

## Idempotency + safety

- **Idempotent:** the script checks for the `tg-bot-link-box` marker
  before inserting. Re-running is a no-op.
- **Backup:** writes `profile.inc.php.pre-tgbot` on first apply (only
  once — the oldest pristine version is preserved).
- **Lint gate:** runs `php -l` on the patched file before replacing the
  original. If lint fails, the original is untouched.
- **Restorable:** `cp profile.inc.php.pre-tgbot profile.inc.php` reverts.

## Re-applying after osTicket upgrades

osTicket upgrades overwrite `include/staff/profile.inc.php`. Re-run the
script after upgrading. Idempotency means it's safe to run on every
upgrade as part of your deploy workflow.

If a future osTicket version changes the `<form action="profile.php"`
anchor line, the script will exit non-zero and tell you. Adjust the
`awk` pattern in the script if that ever happens.
