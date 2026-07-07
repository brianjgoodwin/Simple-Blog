# Backups — Simple Blog production database

**Status:** Live since 2026-07-07. Daily automated local backups of the production
SQLite database, verified on capture.

## What is backed up, and what is NOT

- **Backed up:** the production DB at `/srv/www/simpleblog/database/database.sqlite`
  (users, posts, pages, sessions). This is the only stateful data — everything else
  is code (in git) or rebuildable (caches, built assets).
- **NOT backed up (by design, for now):** the `.env` (holds `APP_KEY` — recreate
  from a password manager or regenerate if lost), uploaded files (none — the app is
  Markdown-only, no images).

## Scope and honest limitation

These are **local backups on the same server**. They protect against:
- accidental deletion (`rm`, overwriting the DB with the dev copy)
- SQLite corruption
- a botched `php artisan migrate`

They do **NOT** protect against **disk failure or loss of the whole server** — the
backups die with the original. That is an accepted gap for now (this is the dev
server; "production" proper lives elsewhere). The upgrade path is offsite: add an
`rclone copy` to object storage (B2 / R2 / self-hosted MinIO) or a push to a
PRIVATE git repo at the end of the backup script. The hard part — a verified,
consistent snapshot — is already done; offsite is a few lines on top.

## How it works

| Piece | Path |
|---|---|
| Backup script | `~/scripts/backup-simpleblog.sh` |
| systemd service (what runs) | `~/.config/systemd/user/backup-simpleblog.service` |
| systemd timer (when) | `~/.config/systemd/user/backup-simpleblog.timer` |
| Backup destination | `~/backups/simpleblog/db-YYYY-MM-DD.sqlite` |

Reference copies of the script and systemd units are committed under
`docs/deploy/` so the repo is self-contained for a rebuild. The **live** files on
the server (paths above) are the source of truth — if you edit them, re-copy into
`docs/deploy/` to keep them in sync.

- **Schedule:** daily at 03:30 (`OnCalendar`), `Persistent=true` so a run missed
  while the server was off catches up at next boot. Runs unattended because linger
  is enabled for the user.
- **Retention:** 14 days; older snapshots pruned by age. One snapshot per day
  (same-day re-runs overwrite the date-stamped file).
- **Consistency:** uses `sqlite3 .backup`, NOT `cp`. The DB runs in WAL mode, so a
  plain copy could miss data in the `-wal` sidecar or catch a torn write.
  `.backup` takes a consistent online snapshot while the app keeps serving.
- **Verification:** every snapshot is checked with `PRAGMA integrity_check`
  immediately after capture. A failed check aborts and does NOT prune old backups,
  so a bad run can never rotate away good history.
- **Permissions:** the backup dir is `700` and files are `600` — the DB contains
  password hashes, so backups are treated as secrets (same discipline as `.env`).

## Everyday commands

```bash
# When did it last run? When's the next run?
systemctl --user list-timers backup-simpleblog.timer

# Read the backup log / history (success + prune counts show here)
journalctl --user -u backup-simpleblog.service

# Take an ad-hoc backup right now (do this before a migration or risky edit)
~/scripts/backup-simpleblog.sh

# List what you've got
ls -la ~/backups/simpleblog/
```

## Restore procedure

If you need to roll back to a snapshot:

```bash
# 1. Stop the app so nothing is writing to the DB during the swap.
systemctl --user stop simple-blog

# 2. (Optional but wise) keep the current DB aside first, in case the restore
#    is a mistake:
cp /srv/www/simpleblog/database/database.sqlite \
   /srv/www/simpleblog/database/database.sqlite.pre-restore

# 3. Copy the chosen snapshot over the live DB.
cp ~/backups/simpleblog/db-YYYY-MM-DD.sqlite \
   /srv/www/simpleblog/database/database.sqlite

# 4. Fix ownership/perms to match what the app expects.
chmod 640 /srv/www/simpleblog/database/database.sqlite

# 5. Restart the app.
systemctl --user start simple-blog

# 6. Verify: browse the site and confirm the data is what you expected.
curl -s -o /dev/null -w '%{http_code}\n' https://simpleblog.brianjgoodwin.dev/@brian
```

Note on WAL sidecars: a restored `.sqlite` from `.backup` is self-contained. If
stale `database.sqlite-wal` / `-shm` files are present from the old DB, SQLite
handles them safely on open, but you can remove them alongside the swap if you want
a perfectly clean state (only while the app is stopped).

## Testing the backups

An untested backup is a hope, not a backup. The daily `integrity_check` gives
ongoing assurance the files are valid SQLite. Periodically (e.g. quarterly), do a
real restore drill: copy a snapshot to a scratch path and open it —
`sqlite3 ~/backups/simpleblog/db-<date>.sqlite 'SELECT count(*) FROM users;'` —
to confirm it actually contains your data, not just that it's structurally valid.
