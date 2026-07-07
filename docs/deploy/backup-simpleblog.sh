#!/usr/bin/env bash
#
# backup-simpleblog.sh — consistent, verified snapshot of the Simple Blog prod DB.
#
# Runs once daily via the systemd --user timer `backup-simpleblog.timer`.
# Keeps 14 days of snapshots, prunes older by age.
#
# WHY sqlite3 .backup and not cp: the DB runs in WAL mode, so a plain copy can
# miss data still in the -wal sidecar or catch a torn write. .backup takes a
# consistent online snapshot while the app keeps serving.
#
# Scope: LOCAL backups only (same server). Protects against accidental deletion
# and corruption, NOT disk/server loss. Add an offsite push here when ready.

set -euo pipefail

DB="/srv/www/simpleblog/database/database.sqlite"
DEST_DIR="$HOME/backups/simpleblog"
RETENTION_DAYS=14

STAMP="$(date +%Y-%m-%d)"
OUT="$DEST_DIR/db-$STAMP.sqlite"

# Backups hold password hashes — keep the dir private (700), created if missing.
mkdir -p "$DEST_DIR"
chmod 700 "$DEST_DIR"

if [[ ! -f "$DB" ]]; then
    echo "ERROR: source DB not found at $DB" >&2
    exit 1
fi

# 1. Consistent snapshot (safe while the app is running).
sqlite3 "$DB" ".backup '$OUT'"
chmod 600 "$OUT"

# 2. Verify the snapshot is not corrupt BEFORE we trust it / prune others.
#    integrity_check prints "ok" on a healthy DB; anything else is a failure.
CHECK="$(sqlite3 "$OUT" 'PRAGMA integrity_check;')"
if [[ "$CHECK" != "ok" ]]; then
    echo "ERROR: integrity check FAILED on $OUT: $CHECK" >&2
    echo "Keeping the bad file for inspection; NOT pruning old backups." >&2
    exit 1
fi

SIZE="$(du -h "$OUT" | cut -f1)"
echo "OK: $OUT ($SIZE), integrity ok"

# 3. Prune snapshots older than RETENTION_DAYS. Only runs after a verified-good
#    backup, so a failed run never deletes good history.
find "$DEST_DIR" -name 'db-*.sqlite' -type f -mtime "+$RETENTION_DAYS" -print -delete

REMAINING="$(find "$DEST_DIR" -name 'db-*.sqlite' -type f | wc -l)"
echo "Pruned to $REMAINING snapshot(s) within $RETENTION_DAYS days."
