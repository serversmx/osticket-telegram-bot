#!/usr/bin/env bash
# Deploy the plugin to a remote osTicket install via SSH + rsync.
#
# Usage:
#   ./scripts/deploy.sh [--dry-run]
#
# Configuration: every variable below can be overridden by exporting it
# before calling this script, or by sourcing a local env file first:
#
#   source scripts/.env && ./scripts/deploy.sh
#
# A template `scripts/.env.example` is provided. Copy it to `scripts/.env`
# (which is gitignored), fill in your values, then run.

set -euo pipefail

# ─── REQUIRED ────────────────────────────────────────────────────────────
# SSH host (must be reachable as: ssh "$REMOTE")
: "${REMOTE:?REMOTE not set — see scripts/.env.example}"
# Absolute path on the remote where the plugin folder will live.
: "${REMOTE_PLUGIN_DIR:?REMOTE_PLUGIN_DIR not set — see scripts/.env.example}"

# ─── OPTIONAL ────────────────────────────────────────────────────────────
# Owner:group to chown deployed files to. Leave empty to skip chown.
REMOTE_USER_GROUP="${REMOTE_USER_GROUP:-}"
# Database name to back up before deploying. Leave empty to skip backup.
REMOTE_DB="${REMOTE_DB:-}"
# Where the SQL backup is written on the remote.
REMOTE_BACKUP_DIR="${REMOTE_BACKUP_DIR:-/tmp}"
# Production URL (printed in the "next steps" hint only).
REMOTE_OSTICKET_URL="${REMOTE_OSTICKET_URL:-https://your-osticket.example.com}"

DRY_RUN=""
if [[ "${1-}" == "--dry-run" ]]; then
    DRY_RUN="--dry-run"
    echo "→ DRY RUN — no changes will be applied"
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_SRC="${SCRIPT_DIR}/../plugin"
if [[ ! -d "$PLUGIN_SRC" ]]; then
    echo "ERROR: plugin source not found at $PLUGIN_SRC" >&2
    exit 1
fi

if [[ -n "$REMOTE_DB" ]]; then
    echo "→ Step 1/4: DB backup ($REMOTE_DB) on $REMOTE"
    if [[ -z "$DRY_RUN" ]]; then
        ssh "$REMOTE" "
            set -e
            mkdir -p '$REMOTE_BACKUP_DIR'
            ts=\$(date +%Y%m%d-%H%M%S)
            out='$REMOTE_BACKUP_DIR'/${REMOTE_DB}-pre-tgbot-\$ts.sql.gz
            mysqldump --single-transaction --quick --routines --triggers '$REMOTE_DB' \
              | gzip > \"\$out\"
            ls -lh \"\$out\"
        "
    else
        echo "  (skipped — dry run)"
    fi
else
    echo "→ Step 1/4: DB backup skipped (REMOTE_DB not set)"
fi

echo "→ Step 2/4: rsync plugin/ → $REMOTE:$REMOTE_PLUGIN_DIR"
ssh "$REMOTE" "mkdir -p '$REMOTE_PLUGIN_DIR'"
rsync -av --delete $DRY_RUN \
    --exclude '.git' --exclude '.DS_Store' --exclude '*.swp' \
    "${PLUGIN_SRC}/" "${REMOTE}:${REMOTE_PLUGIN_DIR}/"

if [[ -n "$REMOTE_USER_GROUP" ]]; then
    echo "→ Step 3/4: chown $REMOTE_USER_GROUP on $REMOTE_PLUGIN_DIR"
    if [[ -z "$DRY_RUN" ]]; then
        ssh "$REMOTE" "sudo chown -R '$REMOTE_USER_GROUP' '$REMOTE_PLUGIN_DIR'"
    else
        echo "  (skipped — dry run)"
    fi
else
    echo "→ Step 3/4: chown skipped (REMOTE_USER_GROUP not set)"
fi

echo "→ Step 4/4: Done"
cat <<MSG

Next manual steps:
  1. Open ${REMOTE_OSTICKET_URL}/scp/
  2. Manage → Plugins → Add New Plugin
  3. Locate "Telegram Bot Notifications (WhatsApp)" → Install
  4. Click into it → fill credentials → Enable
  5. Create a test ticket to verify end-to-end delivery

Rollback:
  ssh $REMOTE 'rm -rf $REMOTE_PLUGIN_DIR'
  (and uninstall from the admin UI)

MSG
