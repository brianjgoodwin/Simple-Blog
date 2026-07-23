# Phase 4 drill: prove the migrate path and the restore path

**Status:** Draft, not yet run.
**Purpose:** Exercise the two code paths that a normal deploy never touches —
`migrate: run`, and restoring from a pre-deploy snapshot.

---

## Why this exists

Every deploy so far has honestly reported `migrate: would skip`, because no
commit added a migration. That means the `migrate: run` branch of the deploy
script has never executed. It would first execute on the day you write a real
migration — which is the worst possible day to discover a bug in it.

The snapshot path has the same problem in sharper form. Three snapshots have
been taken and integrity-checked, but **none has ever been restored**. An
untested restore is not a safety net; it is a belief about a safety net. The
whole stop-and-alert design (resolved decision #2 — no auto-rollback) rests on a
human being able to restore manually from a snapshot. That assumption should be
tested on a day you choose, not during an incident.

This drill tests both at once, using a deliberately inert fixture migration.

---

## The fixture

`database/migrations/2026_07_23_040000_create_deploy_drill_table.php`

A standalone `deploy_drill` table referenced by no model, route, or query.
Applying it cannot affect the running site; a failed drill leaves nothing behind
that matters.

Already verified against the **dev** database (not live): `php -l` clean, listed
as Pending, `up()` creates the table, `migrate:rollback` drops it cleanly.

**It must never merge to `main`.** It lives on a throwaway branch. The reason is
the one we already hit: a migration on `main` is one `git pull` away from
executing against production data.

---

## Preconditions

Do not start unless all of these hold:

- [ ] Live checkout is clean and on `main`, synced with `origin/main`
- [ ] Site returns 200 on `/`, `/privacy`, `/login`
- [ ] The nightly backup timer is **not** about to fire (it runs 03:30 UTC;
      check with `systemctl --user list-timers backup-simpleblog.timer`)
- [ ] You have at least 30 uninterrupted minutes
- [ ] You have read the recovery section at the bottom *before* starting

Record the starting state — you will compare against it:

```bash
cd /srv/www/simpleblog
git rev-parse --short HEAD
git symbolic-ref --short HEAD
sqlite3 database/database.sqlite 'SELECT COUNT(*) FROM posts;'
sqlite3 database/database.sqlite 'SELECT COUNT(*) FROM users;'
sqlite3 database/database.sqlite '.tables'
php artisan migrate:status | tail -3
```

---

## Part 1 — Prove `migrate: run` works

**1. Put the fixture on a throwaway branch and push it.**

```bash
cd ~/developer/simple-blog
git checkout -b drill/migrate-test
git add database/migrations/2026_07_23_040000_create_deploy_drill_table.php
git commit -m "DRILL: inert fixture migration, do not merge"
git push -u origin drill/migrate-test
```

**2. Dry-run against the drill branch first.**

The deploy script deploys from `main` by design. For the drill, temporarily
override the branch — do **not** commit this change:

```bash
# In scripts/deploy.sh, temporarily: readonly BRANCH="drill/migrate-test"
./scripts/deploy.sh --dry-run
```

**Expected:** `migrate : would run (new migration file(s) in the incoming commits)`

This is the first time that line has ever appeared. If it says `would skip`, stop
— the detection logic is wrong and that is the bug the drill exists to find.

**3. Run the real deploy.**

```bash
./scripts/deploy.sh
```

**Verify, in this order:**

- [ ] The snapshot was taken **before** the migrate step (read the log timestamps —
      backup line must precede migrate line)
- [ ] `migrate` reported run, not skip
- [ ] Exit code 0
- [ ] `sqlite3 database/database.sqlite '.tables'` now shows `deploy_drill`
- [ ] Post and user counts are **unchanged** from the preconditions
- [ ] Site still returns 200 on all three routes

The post/user count check is the one that matters most. A migration that
silently damages existing data is the failure mode the snapshot exists for.

---

## Part 2 — Prove the restore path works

This is the half that has never been tested. Do not skip it because Part 1
passed.

**4. Stop the service first.**

Nothing may write to the database during a restore, or you will restore a file
that is immediately diverged from what the app thinks is true.

```bash
systemctl --user stop simple-blog.service
systemctl --user is-active simple-blog.service   # expect: inactive
```

**5. Restore the pre-deploy snapshot.**

Use the path the deploy printed. Handle the WAL sidecars: SQLite in WAL mode
keeps `-wal` and `-shm` files, and a stale sidecar alongside a restored main
file is a corruption risk.

```bash
SNAP=~/backups/simpleblog/db-<timestamp>-predeploy.sqlite   # from the deploy log
DB=/srv/www/simpleblog/database/database.sqlite

# Keep the post-migration state for inspection rather than deleting it.
mv "$DB" "$DB.pre-restore"
rm -f "$DB-wal" "$DB-shm"

install -m 640 "$SNAP" "$DB"
sqlite3 "$DB" 'PRAGMA integrity_check;'    # expect: ok
```

Note `640` — match the original file's mode (`-rw-r-----`), not the snapshot's
`600`, or the service may not be able to read it. Verified: the live DB is
`-rw-r-----`, the snapshot is `-rw-------`, so a plain `cp` would leave the
restored file too restrictive.

**Restore verified in a sandbox** (against a copy of the live DB, not live):
these exact commands reverted a schema change, kept all 19 posts, preserved
`journal_mode=wal`, and produced `integrity_check = ok` at mode 640.

The `rm -f` of the sidecars is defensive and **not** exercised by that sandbox
run — sqlite3 checkpointed and removed them on connection close, so a persistent
stale sidecar could not be reproduced. It is a no-op when they are absent and
matters only when a crashed process leaves them behind. Keep it.

**6. Restart and verify the restore actually reverted the schema.**

```bash
systemctl --user start simple-blog.service
sqlite3 "$DB" '.tables'                    # deploy_drill should be GONE
sqlite3 "$DB" 'SELECT COUNT(*) FROM posts;'   # matches preconditions
php artisan migrate:status | grep drill    # should read Pending again
curl -s -o /dev/null -w '%{http_code}\n' https://simpleblog.brianjgoodwin.dev/
```

- [ ] `deploy_drill` is gone
- [ ] Post and user counts match the preconditions exactly
- [ ] The fixture reads Pending again (schema genuinely reverted)
- [ ] Site returns 200

**If any of these fail, the safety net does not work.** That is a finding, and a
more valuable one than a clean pass. Stop and fix it before Phase 4 ships.

---

## Part 3 — Clean up

```bash
# Revert the BRANCH override in scripts/deploy.sh (never commit it).
git -C ~/developer/simple-blog checkout main
git -C ~/developer/simple-blog branch -D drill/migrate-test
git -C ~/developer/simple-blog push origin --delete drill/migrate-test

# Bring live back to main.
cd /srv/www/simpleblog
git checkout main
./scripts/deploy.sh

# Remove the inspection copy once you are satisfied.
rm /srv/www/simpleblog/database/database.sqlite.pre-restore
```

Final state check — live on `main`, synced, clean tree, no `deploy_drill`,
counts unchanged, site 200.

---

## Recovery, if the drill itself goes wrong

The drill is designed so that the worst case is recoverable, but read this
**before** starting.

- **Migrate failed partway.** The pre-deploy snapshot predates it. Restore per
  Part 2. This is precisely the scenario the snapshot exists for.
- **Restore produced a corrupt DB.** You still have `$DB.pre-restore` (the
  post-migration state) and every nightly snapshot in `~/backups/simpleblog/`.
  Nothing is lost as long as you did not delete those.
- **Site down and you want out immediately.** Restore the most recent nightly
  snapshot, `git checkout main`, run the deploy, restart. Getting back to a
  known-good state matters more than preserving the drill's evidence.
- **Do not** use `migrate:rollback` as the primary recovery on live. It works for
  this fixture because its `down()` is correct and tested, but migrations are not
  reversible in general. The snapshot always restores; a `down()` only sometimes
  does. That asymmetry is why the resolved decision is stop-and-alert with a
  snapshot, not auto-rollback.

---

## What a pass proves

- The `migrate: run` branch executes correctly on a real deploy
- The snapshot is genuinely taken before the migration
- A snapshot can actually be restored, by these steps, on this machine
- The schema truly reverts — not just the table, but Laravel's migration ledger

## What it does not prove

- That a *complex* migration is safe. This fixture is inert by design; a
  migration that rewrites existing rows is a different risk class.
- That restore works under load. The drill stops the service first, which is
  the correct procedure but not the panicked-3am one.
- Anything about `composer install` (Phase 4's other conditional, and a
  supply-chain surface worth its own thought).
