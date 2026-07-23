# Plan: A safe, one-command deploy for Simple Blog

**Status:** Ready for supervised build. Read async, annotate, then drive a
Claude Code session phase by phase.
**Source of truth:** `docs/user-stories/deploy-script.md` (reviewed, three
blocking decisions resolved 2026-07-17). This plan honors those decisions and
does not re-open them.
**Build mode:** Production / bottom-up. This guards a public site. Built right,
fully understood, simple readable bash — not a spike.

---

## Concept Summary

Build `scripts/deploy.sh` in the Simple Blog repo: one command that refreshes
the **live** checkout at `/srv/www/simpleblog` from `origin/main` in the correct,
order-sensitive sequence, with a verified DB snapshot taken before any migration
and an end-to-end HTTP-200 check after restart. It runs conditional work only
(composer / migrate / build fire only when their inputs changed), refuses to run
on a dirty or diverged tree, guards against overlapping runs with `flock`, and
supports two non-mutating modes: `--check` (is live behind main?) and
`--dry-run` (what would this run?). On any failure or a non-200 verify it
**stops and alerts** with exact recovery information — it never auto-rolls-back.

Implementation-angle clarification the story didn't nail down: the target of
every command is the **live** checkout `/srv/www/simpleblog`, which is a
*different* git checkout from your dev copy at `~/developer/simple-blog` and
points at the **production** SQLite DB. The script must `cd` to the live path
explicitly and never assume its own location implies the working directory —
because the source of truth lives in the repo but the running copy lives at
`/srv/www/simpleblog`. This distinction is load-bearing; see the Architecture
section.

---

## Scope & Complexity Assessment

This is a well-matched project for a solo dev on nights and weekends, and it is
worth doing bottom-up. A few honest observations from the implementation angle:

- **The complexity is in the sequencing and the failure paths, not the volume of
  code.** The happy path is maybe 40 lines. The value — and the risk — is in the
  order (backup before migrate, build after pull, verify after restart) and in
  the stop-and-alert path being genuinely useful when you're tired at 11pm. Budget
  your attention accordingly: the "what happens when it breaks" code deserves more
  care than the "it worked" code.

- **The single genuinely irreversible action is `migrate --force`.** Everything
  else is recoverable by re-running. The whole safety design orbits this one line:
  snapshot immediately before it, and on any downstream failure point the human at
  that snapshot. Keep that framing front of mind while building.

- **This script does not add CI and should not pretend to.** No test gate in v1
  (resolved). It makes the correct manual sequence the easy one; it does not make
  the code safe to ship. That's a separate future story. Don't let scope creep
  pull a test runner into this script — the prod-DB-wipe risk is exactly why it's
  deferred.

- **Operational burden is low and that's by design.** No new services, no new
  daemons, no external dependencies. It reuses the existing backup script and the
  existing systemd unit. The only new artifact is one bash file plus a symlink.
  This is the right weight for the problem.

- **One real drift risk in the design itself:** the script lives in the repo but
  operates on the live checkout. If you edit `scripts/deploy.sh` in your dev copy,
  commit, and push — the live box only gets the new script *after a deploy pulls
  it*. So a deploy always runs the version of the script that was live *before*
  this deploy's pull. That's actually the safe ordering (you don't want a pull to
  swap the script mid-run), but it means "I fixed the deploy script" isn't true on
  the live box until one deploy later. Note it; don't fight it.

- **A caution about the existing backup script's filename.** `backup-simpleblog.sh`
  names its output `db-YYYY-MM-DD.sqlite` — date only, no time. Two deploys on the
  same day both target the same filename; `sqlite3 .backup` overwrites it. For a
  once-daily timer that's fine, but for a *deploy* snapshot you want the snapshot
  that existed at *this deploy's* start, and a second deploy that day would clobber
  it. This is an Open Question below — decide how to handle it before Phase 3.

Bottom line: right-sized, worth the rigor, low ongoing maintenance. The care goes
into failure handling and the live-vs-dev checkout distinction, not into fighting
complexity.

---

## Architecture & Key Technical Decisions

### Decision: How the script locates the live checkout

- **Option A: Hardcode `readonly APP_DIR="/srv/www/simpleblog"` at the top and
  `cd` to it.** Dead simple, obvious, impossible to misfire. The live path is a
  stable fact of this deployment (documented in the Caddy setup and MEMORY.md).
- **Option B: Derive the app dir from the script's own location** (`$BASH_SOURCE`).
  Clever, "portable" — but wrong here: the source-of-truth copy is in the repo and
  the invoked copy is a symlink in `~/scripts/`, so `$BASH_SOURCE` points at neither
  the repo nor reliably at the live checkout.
- **Recommendation: Option A.** This is a Simple-Blog-specific script (explicit
  non-goal: not multi-app). A hardcoded, clearly-named constant is the simple,
  obvious, months-from-now-readable choice. Guard it: assert the dir exists and is
  a git repo before doing anything. **Decide before build.**

### Decision: Detecting what work is needed (the conditional steps)

The story wants composer/migrate/build to run only when needed. The clean way is
to compute this from the git diff of the pull.

- **composer:** run `composer install` only if `composer.lock` changed. Detect via
  `git diff --name-only OLD_SHA NEW_SHA -- composer.lock` (non-empty ⇒ run).
- **build:** run `npm run build` (which is `vite build` — confirmed in
  `package.json`) only if anything under `resources/` changed. Same diff pattern,
  path `resources/`.
- **migrate:** the story says "only if migrations are actually pending." The
  robust check is `php artisan migrate:status` and look for pending, **not** a git
  diff of the migrations dir — a migration can be pending for reasons other than
  this pull, and a file diff can miss squashed/renamed migrations. Prefer asking
  Laravel. In `--dry-run`, `migrate:status` is itself read-only and safe to run.
- **Recommendation:** capture `OLD_SHA` (pre-pull `HEAD`) and `NEW_SHA`
  (`origin/main` after fetch) once, up front, and drive composer/build decisions
  off `git diff --name-only "$OLD_SHA" "$NEW_SHA" -- <path>`. Drive the migrate
  decision off `migrate:status`. State each decision explicitly in output
  ("composer.lock unchanged in this pull, skipping"). **Decide before build**
  (it shapes the whole middle of the script).

Laravel migrate reference: <https://laravel.com/docs/12.x/migrations#running-migrations>
(migrate, `--force`, and `migrate:status` are on this page). *(verify the version
segment matches the app's Laravel version)*

### Decision: fast-forward-only pull and the clean-tree assertion

- Assert a clean working tree with `git status --porcelain` (empty output ⇒ clean).
  Refuse otherwise — the live box should never carry uncommitted edits.
- `git fetch origin`, then `git merge-base --is-ancestor HEAD origin/main` to
  confirm live is strictly behind (or level with) main. If it isn't an ancestor,
  the branches have diverged → refuse. This is a cleaner signal than parsing
  `git pull --ff-only`'s failure, and it's exactly what `--check` needs too.
- Then `git pull --ff-only` (belt and suspenders — it will also refuse a
  non-ff, but you've already given a clear message).
- **Recommendation:** do the `merge-base` check explicitly so `--check`,
  `--dry-run`, and the real deploy all share one "how far behind are we?"
  computation. `git rev-list --count HEAD..origin/main` gives the commit count for
  human-readable drift output. **Deferrable detail**, but the shared computation is
  worth designing up front.

Git reference: <https://git-scm.com/docs/git-merge-base> and
<https://git-scm.com/docs/git-pull> (see `--ff-only`).

### Decision: The lock (`flock`)

- **Recommendation:** wrap the mutating body in `flock -n` on a lockfile (e.g.
  `/tmp/deploy-simpleblog.lock` or `~/.local/state/deploy-simpleblog.lock`). `-n`
  = fail immediately if another run holds it, rather than queueing. This also
  protects against a deploy colliding with the nightly `backup-simpleblog.timer`
  *if* you choose to share a lock — see Open Questions. `--check` and `--dry-run`
  are read-only and need not take the lock (decide: taking it is harmless and
  simpler to reason about). **Deferrable**, but trivial to include from the start.

`flock` reference: <https://man7.org/linux/man-pages/man1/flock.1.html>
(the "flock ... within a script" example near the bottom is the pattern to copy).

### Decision: Reusing the backup script for the pre-deploy snapshot

- **Recommendation:** call `~/scripts/backup-simpleblog.sh` and capture its
  output. It already does the hard part (WAL-aware `.backup`, integrity check,
  correct perms). It prints `OK: <path> (<size>), integrity ok` on success. Parse
  that line for the snapshot path, or — cleaner — know the path is
  `~/backups/simpleblog/db-$(date +%Y-%m-%d).sqlite` and confirm the script exited
  0. **If the backup script exits non-zero, STOP before pulling — never migrate
  without a verified snapshot in hand.** Then (resolved) copy that verified file
  to a `db-<full-timestamp>-predeploy.sqlite` so the deploy's snapshot has a name
  that can't be clobbered by a same-day nightly or a second deploy; recovery
  points at the `-predeploy` copy.

### Decision: The end-to-end verify

- **Recommendation:** after restart, `curl -s -o /dev/null -w '%{http_code}'
  https://simpleblog.brianjgoodwin.dev/` and expect `200`. Retry a small fixed
  number of times (e.g. 3, with a short sleep) for artisan-serve warmup. Hit the
  **public URL through Caddy**, not `localhost:8001` — that's what "it works" means
  to a visitor, and it also confirms Caddy → host wiring. Use `--max-time` so a
  hang can't wedge the deploy. On non-200 after retries → stop-and-alert.
- Non-goal reminder from the story: a brief blip during restart is acceptable
  (artisan serve restarts sub-second). No zero-downtime work.

### Decision: Output style and strict mode

- `set -euo pipefail` at the top; script perms `700`; never echo secret values
  (the script reads `.env` only indirectly, via artisan — don't print env).
- Every step prints a timestamped, labeled line. Skips are stated explicitly.
  A small `log()` helper (`printf '%s  %s\n' "$(date +%H:%M:%S)" "$*"`) keeps this
  simple and consistent. Model the strict-mode / verify-before-trust style on the
  existing `backup-simpleblog.sh` — it's a good local reference for the house style.

---

## Implementation Plan

Six phases, each independently testable, sequenced so every non-mutating path is
proven before any mutating path exists. Each is a 1–4 hour supervised session.
Phases 1–3 never touch the live DB or service, which is deliberate: you build and
trust the whole read-only skeleton first.

---

**Phase 1: Skeleton, strict mode, arg parsing, and the read-only "check" path**
*Get a runnable script that can inspect the live checkout and report drift, but
mutates nothing. This is the foundation every other phase builds on, and it's
100% safe to run against production because it only reads.*

- [ ] Create `scripts/` in the repo; add `scripts/deploy.sh` with `set -euo pipefail`.
- [ ] Add the `log()` timestamped-output helper.
- [ ] Define `readonly APP_DIR="/srv/www/simpleblog"`; assert it exists and is a
      git repo; `cd` into it (Architecture: Decision A).
- [ ] Parse args: support `--check`, `--dry-run`, `--help`. Reject unknown flags.
- [ ] Implement the shared drift computation: `git fetch origin`; compute
      `OLD_SHA` (HEAD), `NEW_SHA` (origin/main), commit count `HEAD..origin/main`,
      and the diverged/ancestor check via `git merge-base --is-ancestor`.
- [ ] Implement `--check`: print "live is N commits behind main" (or "up to date",
      or "DIVERGED — refuse"), then exit 0 without changing anything.
- [ ] `chmod 700 scripts/deploy.sh`.
- [ ] Manually test `--check` against live in several states (up to date; after a
      commit lands on main so it reports "behind").

Agents: `code-reviewer` after this phase (bash correctness, quoting, strict-mode
traps). `security-code-reviewer` is worth a pass here too, since this is the phase
that establishes how the script touches the repo and filesystem.

---

**Phase 2: `--dry-run` — decide and report all conditional work, still no mutation**
*Extend the read-only path to compute and print exactly what a real deploy WOULD
do: which of composer/migrate/build would run and why. This proves the decision
logic in isolation, before that logic can trigger anything destructive.*

- [ ] Compute the composer decision: `git diff --name-only "$OLD_SHA" "$NEW_SHA"
      -- composer.lock`.
- [ ] Compute the build decision: same diff, path `resources/`.
- [ ] Compute the migrate decision via `php artisan migrate:status` (read-only).
- [ ] Implement `--dry-run`: print incoming commits (`git log --oneline
      OLD..NEW`), then each decision as an explicit line ("composer: would run —
      composer.lock changed" / "migrate: nothing pending, would skip" / etc.), then
      exit 0. Touch nothing.
- [ ] Verify `--dry-run` output is honest against a real pending change and against
      an up-to-date tree.

Agents: `code-reviewer` after this phase (the decision logic is the brain of the
script; get it reviewed before it drives real actions).

---

**Phase 3: The backup + pull core (mutating, but the safe half)**
*Introduce the first mutations: take the verified snapshot, then fast-forward the
checkout. Stop before composer/migrate/build. After this phase the script can
safely advance the live code, with a snapshot always taken first.*

- [ ] Invoke `~/scripts/backup-simpleblog.sh`; on non-zero exit, STOP (do not
      pull). Then copy its output to a timestamped `-predeploy` snapshot
      (`db-<full-timestamp>-predeploy.sqlite`) per the resolved decision — this
      is the snapshot recovery points at, immune to same-day overwrite. Capture
      and print the `-predeploy` path.
- [ ] Add the clean-working-tree assertion (`git status --porcelain`); refuse if
      dirty.
- [ ] Add the diverged-tree refusal (reuse Phase 1's `merge-base` check).
- [ ] `git pull --ff-only`. Confirm HEAD now equals NEW_SHA.
- [ ] Print a partial summary (snapshot path, OLD_SHA, NEW_SHA) and exit 0 —
      later phases append to this.
- [ ] Test on a real "live is a few commits behind" state. Confirm a snapshot lands
      in `~/backups/simpleblog/` and the checkout fast-forwards.
- [ ] Test the refusals: dirty tree ⇒ refuse; simulate divergence ⇒ refuse.

Agents: `code-reviewer` and `security-code-reviewer` after this phase — it's the
first phase that writes to disk and moves the live checkout; the backup-handoff and
refusal logic are exactly where a subtle bug would hide.

---

**Phase 4: Conditional composer / migrate / build (the ordered mutating middle)**
*Wire the Phase 2 decisions to real actions, in the correct order. Backup is
already taken (Phase 3) before migrate runs here — that ordering is the whole
safety story, so preserve it.*

- [ ] If composer decision = run: `composer install --no-dev --optimize-autoloader`
      (resolved flags for the live box).
- [ ] If migrate decision = run: `php artisan migrate --force`. This is the one
      irreversible step; the snapshot from Phase 3 is its safety net.
- [ ] If build decision = run: `npm run build` (`vite build`).
- [ ] Each conditional prints run-vs-skip explicitly with reasoning.
- [ ] `php artisan optimize:clear` after the above.
- [ ] Verify ordering by reading the script top to bottom: backup → pull →
      composer → migrate → build → optimize:clear. Wrong order is the classic bug.
- [ ] Test a deploy where migrations ARE pending (against a throwaway commit that
      adds a trivial migration), confirming the snapshot precedes the migrate.

Agents: `code-reviewer` after this phase. Consider a second `security-code-reviewer`
pass since this phase runs `composer install` (supply-chain surface) and mutates
the prod schema.

---

**Phase 5: flock, restart, end-to-end verify, and the final summary**
*Make the deploy concurrency-safe, restart the service, and prove the site
actually serves a 200 before declaring success.*

- [ ] Wrap the mutating body in `flock -n` on a lockfile; on lock-held, exit with a
      clear "another deploy is running" message.
- [ ] `systemctl --user restart simple-blog`.
- [ ] Verify: `curl` the public homepage, expect 200, retry a few times with
      `--max-time` and a short sleep for warmup.
- [ ] On 200: print the final success summary — commit shipped (short SHA + subject),
      steps run vs skipped, snapshot path, elapsed time.
- [ ] Test a full clean deploy end to end and read the whole log.
- [ ] Test concurrency: start a deploy, launch a second in another shell, confirm
      the second refuses via the lock.

Agents: `code-reviewer` after this phase.

---

**Phase 6: The stop-and-alert path (deliberately provoke every failure)**
*The most important phase and the easiest to skip. The safety net only counts if
it's been fired on purpose. Build a single `stop_and_alert()` and trip it from
each failure point.*

- [ ] Implement `stop_and_alert()`: prints WHERE it stopped, the site's current
      state (did the service restart? is the schema migrated?), the pre-deploy
      snapshot path, and OLD_SHA (previous commit) for manual recovery. Exits
      non-zero. It must NOT auto-restore (resolved decision).
- [ ] Route every mutating step's failure through it (backup fail, pull fail,
      composer fail, migrate fail, build fail, restart fail, verify non-200). With
      `set -e`, use a trap or explicit checks — decide which reads more clearly.
- [ ] **Deliberately break the verify:** temporarily point the curl at a bad path
      / stop the service so it returns non-200, and confirm the alert prints
      complete, correct recovery info.
- [ ] **Deliberately fail a migration** (a bad throwaway migration on a scratch
      copy — NOT against the real prod DB) and confirm the alert names the snapshot
      and previous commit.
- [ ] Write the matching manual-recovery runbook into
      `docs/` (stop service → restore snapshot handling WAL sidecars → `git reset
      --hard OLD_SHA` → rebuild → restart). The story already sketched these steps;
      make them copy-pasteable.
- [ ] Symlink into place: `ln -s /srv/www/simpleblog/scripts/deploy.sh
      ~/scripts/deploy-simpleblog.sh` (resolved: symlink, not copy — repo is
      source of truth, `~/scripts/` is the invocation point; the script updates
      itself one-deploy-behind, which is the safe ordering).
- [ ] Update `MEMORY.md` / the deploy-model note and the README with the new
      one-command flow.

Agents: `code-reviewer` and `security-code-reviewer` on the finished script.
The `security-code-reviewer` should specifically confirm: perms are 700, no secret
values are logged, the lockfile location isn't world-writable in a way that enables
a symlink attack, and failures never leave the site in a silently-broken state.

---

## Resolved decisions (2026-07-17)

- **Pre-deploy snapshot naming — RESOLVED: option (b).** After calling
  `backup-simpleblog.sh`, the deploy makes its own timestamped copy named
  `db-<full-timestamp>-predeploy.sqlite` (distinct from the date-only nightly
  file it can't clobber and can't be clobbered by). The stop-and-alert path
  points recovery at *this* `-predeploy` file, not the shared nightly name.
  Rationale: `backup-simpleblog.sh` names its file date-only (`db-YYYY-MM-DD.sqlite`),
  so a second same-day run overwrites it — unacceptable for a snapshot you may
  be mid-recovery against. This changes Phase 3.

- **`composer install` flags — RESOLVED: `--no-dev --optimize-autoloader`.**
  Correct for the live box. Confirmed: tests are never run from the live
  checkout, so the missing dev dependencies (Pest) don't matter. Consistent with
  "no test gate in v1." This settles Phase 4.

- **Symlink vs copy into `~/scripts/` — RESOLVED: symlink.** The invoked
  `~/scripts/deploy-simpleblog.sh` is a symlink to the live checkout's
  `scripts/deploy.sh`. Accepted trade-off: a deploy that pulls a new
  `scripts/deploy.sh` changes what runs *next* time, not mid-run — which is the
  safe ordering (the script never rewrites itself while executing). This settles
  Phase 6.

## Open Questions

Two genuinely phase-time items remain; both are low-stakes and decided while
building the phase named, not before.

- [ ] **Should the deploy share a lock with the nightly backup timer?** A deploy
      runs the backup mid-sequence; the nightly `backup-simpleblog.timer` could
      also fire. Two `sqlite3 .backup` runs are individually safe, but a nightly
      backup landing between your pre-deploy snapshot and your migrate muddies the
      "which snapshot is the pre-deploy one" story. Options: separate locks (simple,
      accept the small overlap window) or a shared lock so they can't interleave.
      Low stakes; decide during Phase 5.

- [ ] **Verify target: public URL vs localhost.** Recommended the public URL
      (through Caddy) so "it works" means what a visitor sees. Trade-off: if
      Cloudflare or Caddy hiccups, the deploy reports failure even though the app is
      fine, and you'd stop-and-alert on an infra blip rather than an app problem. A
      belt-and-suspenders option: check `localhost:8001` first (is the app up?) and
      the public URL second (is the whole path up?), reporting which layer failed.
      Decide during Phase 5.

---

## References

- Reviewed user story — `docs/user-stories/deploy-script.md`
- Serving/security recipe — `~/documents/caddy-docker-setup.md` (§7 host-app
  recipe, §8 security model)
- Existing backup script (style model + reused for the snapshot) —
  `~/scripts/backup-simpleblog.sh`
- Laravel migrations (migrate, `--force`, `migrate:status`) —
  <https://laravel.com/docs/12.x/migrations#running-migrations> *(verify version
  segment against the app's Laravel version)*
- `git merge-base` (ancestor/divergence check) — <https://git-scm.com/docs/git-merge-base>
- `git pull` (`--ff-only`) — <https://git-scm.com/docs/git-pull>
- `flock` (script-locking pattern) — <https://man7.org/linux/man-pages/man1/flock.1.html>
- Bash strict mode background (`set -euo pipefail`) —
  <http://redsymbol.net/articles/unofficial-bash-strict-mode/> *(older but still the
  clearest single explanation; verify it's reachable)*
