#!/usr/bin/env bash
#
# Patches osTicket's include/staff/profile.inc.php to add a
# "Gestionar Telegram" button at the top of every staff member's profile.
#
# This is a CORE FILE MODIFICATION — osTicket has no plugin hook to inject
# UI into the staff profile page natively, and on the timeline a plugin's
# bootstrap() runs the global $ost isn't available yet so addExtraHeader()
# from there doesn't work. Patching the template is the only reliable path.
#
# Behavior:
#   - Idempotent: re-running is a no-op if the patch is already present.
#   - Backs up the original to profile.inc.php.pre-tgbot on first apply.
#   - Lints the result with `php -l` before writing.
#   - Restorable: `cp profile.inc.php.pre-tgbot profile.inc.php` reverts.
#
# Re-apply after osTicket upgrades.
#
# Usage (locally, from anywhere):
#   ssh <your-server> 'bash -s' < scripts/apply-staff-profile-patch.sh
#
# Or copy + run on the server:
#   scp scripts/apply-staff-profile-patch.sh <your-server>:/tmp/
#   ssh <your-server> 'bash /tmp/apply-staff-profile-patch.sh'
#
# Env vars you can override:
#   OSTICKET_PROFILE  default /var/www/osticket/include/staff/profile.inc.php
#   PHP_CLI           default `php` (whatever is on PATH)
#   WEB_OWNER         default www-data:www-data
#
# @license GPL-2.0-or-later

set -euo pipefail

PROFILE="${OSTICKET_PROFILE:-/var/www/osticket/include/staff/profile.inc.php}"
PHP_CLI="${PHP_CLI:-php}"
WEB_OWNER="${WEB_OWNER:-www-data:www-data}"
BACKUP="${PROFILE}.pre-tgbot"

if [[ ! -f "$PROFILE" ]]; then
    echo "ERROR: profile.inc.php not found at $PROFILE" >&2
    echo "Set OSTICKET_PROFILE to the correct path." >&2
    exit 1
fi

# Idempotency check
if grep -q "tg-bot-link-box" "$PROFILE"; then
    echo "Patch already applied — nothing to do."
    exit 0
fi

# Backup (only if we don't already have one — preserve oldest pristine)
if [[ ! -f "$BACKUP" ]]; then
    cp -p "$PROFILE" "$BACKUP"
    echo "Backup created: $BACKUP"
fi

TMP="$(mktemp)"

# Insert the button block right after <form action="profile.php" ...>
awk '
  /^<form action="profile\.php"/ && !inserted {
    print
    print ""
    print "<!-- BEGIN telegram-bot plugin patch (see https://github.com/RenatoAscencio/osticket-telegram-bot) -->"
    print "<div id=\"tg-bot-link-box\" style=\"padding:14px 18px;background:#eff6ff;border-left:4px solid #2563eb;border-radius:6px;margin:0 0 18px;font-size:14px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;\">"
    print "  <div style=\"flex:1;min-width:240px;\">"
    print "    <strong style=\"font-size:1.05em;\">\xF0\x9F\x93\xB2 Notificaciones por Telegram</strong>"
    print "    <div style=\"color:#475569;margin-top:4px;\">Vincula tu cuenta de Telegram con un clic — recibe alertas de tickets directamente en tu chat.</div>"
    print "  </div>"
    print "  <a href=\"link-telegram.php\" class=\"button\" style=\"background:#2563eb;color:#fff;text-decoration:none;padding:9px 16px;border-radius:6px;white-space:nowrap;\">\xF0\x9F\x94\x97 Gestionar Telegram</a>"
    print "</div>"
    print "<!-- END telegram-bot plugin patch -->"
    print ""
    inserted = 1
    next
  }
  { print }
  END {
    if (!inserted) {
      print "AWK: did not find <form action=\"profile.php\" anchor" > "/dev/stderr"
      exit 2
    }
  }
' "$BACKUP" > "$TMP"

# Lint the result before writing
if ! "$PHP_CLI" -l "$TMP" > /dev/null; then
    echo "ERROR: patched file failed php -l — aborting" >&2
    "$PHP_CLI" -l "$TMP" >&2
    rm -f "$TMP"
    exit 3
fi

# Confirm the marker is actually there
if ! grep -q "tg-bot-link-box" "$TMP"; then
    echo "ERROR: patch marker missing from output — aborting" >&2
    rm -f "$TMP"
    exit 4
fi

# Apply
cp "$TMP" "$PROFILE"
chown "$WEB_OWNER" "$PROFILE"
chmod 644 "$PROFILE"
rm -f "$TMP"

echo "✅ Patch applied to $PROFILE"
echo "   Backup at:  $BACKUP"
echo "   To revert:  cp $BACKUP $PROFILE && chown $WEB_OWNER $PROFILE"
