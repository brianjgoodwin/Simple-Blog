# User Story: A safe, one-command deploy for Simple Blog

**Status:** Draft — three blocking decisions resolved (2026-07-17); ready for
project-planner once you've reviewed the rest.
**Captured:** 2026-07-17

## Resolved decisions (2026-07-17)

1. **Test gate: SKIP for now.** The deploy script does not run the test suite.
   The prod-DB-wipe risk (Decision #1 below) is avoided entirely by not going
   there yet. A test gate is a later story, paired with real pre-merge CI.
   → Story #7 drops out of the "should have" set for v1.
2. **On failure: STOP AND ALERT, not auto-rollback.** If any step fails (or
   verification returns non-200), the script stops, prints exactly where it
   stopped and what state the site is in, and points at the pre-deploy snapshot
   + previous commit for manual recovery. It does NOT attempt an automatic
   restore. Rationale: automated rollback against a live DB is where subtle bugs
   hide; a human inspecting the wreckage with the snapshot in hand is safer.
   → Story #6 changes from "rolls back automatically" to "stops and reports
   recovery instructions."
3. **Source of truth: IN THE REPO.** The script lives in the repo (e.g.
   `scripts/deploy.sh`), version-controlled with the app, and is symlinked or
   copied to `~/scripts/deploy-simpleblog.sh` for invocation. Deploy process
   travels with the code and has history.

**Context:** During a manual "refresh the live site" session, the live checkout
(`/srv/www/simpleblog`) was found six commits behind `main` — it had been
serving stale code for days with no signal that it had drifted. The refresh
then took six manual steps (backup, pull, conditional composer, migrate, build,
restart, verify), each easy to skip or misorder. This story turns that dance
into one reviewable command with a safety net.

---

## The core want

> As the operator of Simple Blog, I want to deploy the latest `main` to the
> live site with a single command that is safe by default, so that I stop
> hand-running a six-step sequence, stop silently drifting behind `main`, and
> can recover instantly if a deploy goes wrong.

---

## Why this matters (the honest problem statement)

This is a solo-operated, publicly-reachable site with **no CI/CD gate**:

- Nothing runs the test suite before code goes live.
- Nothing signals when the live checkout has fallen behind `main`.
- The one genuinely irreversible step (DB migration) depends on a human
  remembering to back up first.
- The steps are order-sensitive: build-before-pull produces stale assets;
  migrate-before-backup removes the safety net.

The risk isn't hypothetical — the drift already happened once. A script does not
add CI/CD, but it makes the *correct* sequence the *easy* one, and bakes in the
backup and verification a tired human skips.

---

## User stories

### Must have (the safe happy path)

1. **As the operator, I run one command** (e.g. `~/scripts/deploy-simpleblog.sh`)
   and it performs the whole refresh in the correct order, so I never have to
   remember the sequence.

2. **As the operator, I get a verified DB backup taken automatically before any
   migration**, reusing the existing `backup-simpleblog.sh` (WAL-aware,
   integrity-checked), so a bad migration is always recoverable.

3. **As the operator, the script only does work that's needed**, so routine
   deploys are fast and honest:
   - `composer install` only if `composer.lock` changed in the pull.
   - `php artisan migrate` only if migrations are actually pending.
   - `npm run build` only if anything under `resources/` changed.

4. **As the operator, I want a fast-forward-only pull**, so the deploy refuses
   to proceed on a diverged or dirty checkout rather than creating a merge
   commit on the live box.

5. **As the operator, I want the deploy verified end-to-end before it's declared
   done** — an HTTP 200 from `https://simpleblog.brianjgoodwin.dev/` *after*
   the service restart — so "it finished" and "it works" are the same claim.

6. **As the operator, if any step fails or verification fails, the deploy stops
   and tells me exactly how to recover** — where it stopped, the current site
   state, the path to the pre-deploy DB snapshot, and the previous commit hash —
   so I can restore manually with full information. It does NOT auto-rollback.
   (Resolved: stop-and-alert, not self-heal.)

### Should have

7. ~~Test suite gate~~ — **deferred out of v1** (resolved: skip). Left here as a
   placeholder for the future story that pairs a deploy-time or pre-merge test
   gate with a safe test database.

8. **As the operator, I want a `--dry-run` / preview mode** that shows what would
   change (incoming commits, whether composer/migrate/build would run) without
   touching anything, so I can review a deploy before committing to it.

9. **As the operator, I want clear, ordered, timestamped output** — each step
   labeled, skips stated explicitly ("composer: unchanged, skipped") — so the
   log is a readable record and silent skips never masquerade as "done."

10. **As the operator, I want a drift check I can run anytime** (is live behind
    `main`?) so I get a signal *before* the drift becomes a surprise. Could be
    the deploy script's `--check` mode, or a separate tiny script.

### Could have (later)

11. A `systemd --user` timer or a git-hook trigger, so deploys can be scheduled
    or fire on push — only after the manual script is trusted.
12. An offsite copy of the pre-deploy backup (the backup script already flags
    "add an offsite push when ready").
13. A one-line deploy notification (local mail / log line) recording what shipped.

---

## Explicit non-goals (scope fence)

- **Not** a general-purpose or multi-app deploy tool. This is Simple-Blog-
  specific; Puzzlebox can copy the pattern later if it earns its place.
- **Not** zero-downtime deploys. `artisan serve` restarts in well under a second;
  a brief blip is acceptable for this site. (If that ever matters, the answer is
  php-fpm, not a fancier script — see the Caddy setup doc.)
- **Not** a replacement for real CI. This runs *on the server at deploy time*.
  Pre-merge CI (tests on every PR before it reaches `main`) is a separate,
  complementary want worth its own story.

---

## Technical implications & decisions to make

These are the choices that change the shape of the script. Flagged for your
annotation — don't want to guess on a script that touches the live DB.

1. ~~Test DB safety~~ — **moot in v1** (no test gate). When the future test-gate
   story is picked up, this is the blocking question to answer first: Pest tests
   truncate/seed, and the live checkout points at the **production SQLite DB**,
   so a naive `php artisan test` here would wipe live data. The gate must run
   against a separate test DB or in a non-prod checkout. Flagged for that story,
   not this one.

2. **Recovery (manual, per stop-and-alert).** No auto-restore, but the script
   must leave recovery *easy*: on failure it prints the snapshot path and the
   pre-deploy commit hash, and (in the docs) the exact restore steps — stop the
   service first so nothing writes mid-restore, copy the snapshot back, handle
   WAL sidecars, `git reset --hard <prev>`, rebuild, restart. Note: a migration
   that already ran leaves the schema ahead of a reverted checkout, which is
   *why* restoring the verified snapshot (not `migrate:rollback`) is the correct
   manual recovery — migrations aren't guaranteed reversible; a snapshot always
   restores.

3. **`set -euo pipefail` + explicit failure handling.** The script must abort on
   any step's failure and trigger rollback, not barrel ahead. The backup script
   is a good style model (strict mode, verify-before-trust, no destructive action
   after a failure).

4. **Idempotency & concurrency.** Two deploys at once, or a deploy during the
   nightly backup timer, shouldn't interleave. A simple lockfile (`flock`) guards
   this.

5. **Where it lives / how it's invoked.** `~/scripts/deploy-simpleblog.sh`,
   matching `backup-simpleblog.sh`. Consider committing a copy into the repo
   (`scripts/` or `docs/`) so the deploy process is version-controlled with the
   app, then symlinked/copied to `~/scripts/`. Decision: source-of-truth in repo,
   or in `~/scripts/`?

6. **Permissions / secrets.** The script reads `.env` (prod secrets) and writes
   the DB. Runs as `brian` (same as the service). No new secret exposure, but
   the script itself should be `700` and never log secret values.

---

## Rough shape (illustrative — not the plan, just to anchor discussion)

```
deploy-simpleblog.sh [--dry-run] [--check] [--no-tests]

  0. flock — refuse to run if another deploy is in progress
  1. cd /srv/www/simpleblog; assert clean working tree
  2. git fetch; compute incoming commits
       --check  → print drift status and exit
       --dry-run→ print what WOULD run (composer/migrate/build?) and exit
  3. record CURRENT_COMMIT (for rollback)
  4. run backup-simpleblog.sh; capture the snapshot path
  5. git pull --ff-only
  6. composer install   (only if composer.lock changed)
  7. (no test gate in v1 — resolved)
  8. php artisan migrate --force   (only if pending)
  9. npm run build      (only if resources/ changed)
 10. php artisan optimize:clear
 11. systemctl --user restart simple-blog
 12. curl-verify homepage == 200  (retry a couple times for warmup)
       fail → STOP: print where it stopped, current site state, the snapshot
              path and CURRENT_COMMIT for manual recovery; exit non-zero.
              Do NOT auto-restore.
 13. print summary: commit shipped, steps run vs skipped, snapshot path
```

---

## Open questions for you

The three blockers are resolved (see top of doc). Remaining, minor:

- **Build/learning mode.** This is a real script guarding a public site, so the
  default is **production / bottom-up** — built right, understood fully, no
  hand-waving. Say so if you'd rather spike a rough version first to feel out the
  shape before hardening it.
- **Exact repo path for the script** — `scripts/deploy.sh` is the natural home
  (the repo has no `scripts/` dir yet; it'd be created). Confirm or redirect.
- **Alert channel on failure** — for now, a clear non-zero exit + printed
  instructions is enough (you run it interactively). Local mail / a log line is a
  "could have" for when deploys become non-interactive.
