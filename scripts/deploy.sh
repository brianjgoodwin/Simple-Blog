#!/usr/bin/env bash
#
# deploy.sh — safe, one-command deploy for Simple Blog.
#
# Source of truth lives in the repo (this file). It is invoked in production via
# a symlink at ~/scripts/deploy-simpleblog.sh (added in a later phase).
#
# Design: safe by default. Read-only modes (--check, --dry-run) never mutate the
# live site. The mutating deploy path (no flag) is built up in later phases. On
# any failure the script STOPS and reports how to recover — it never auto-rolls-
# back. See docs/deploy-script-plan.md for the full plan and resolved decisions.
#
# Complete (Phases 1-5): --check and --dry-run (read-only), and the full mutating
# deploy — verified DB backup, fast-forward pull, conditional composer / migrate
# / build, optimize:clear, service restart, and an end-to-end HTTP check that the
# public site really returns 200 before success is declared. Serialised by flock.
# Phase 6 (richer stop-and-alert reporting) is still to come.

set -euo pipefail

# --- Constants ---------------------------------------------------------------

# The LIVE checkout. NOT ~/developer/simple-blog (that's a separate dev copy with
# its own database). Everything this script does targets the served checkout.
readonly APP_DIR="/srv/www/simpleblog"

# The branch we deploy from, and its remote.
readonly REMOTE="origin"

# The branch a deploy uses unless --branch overrides it. Kept separate from
# BRANCH so the override is always visibly a deviation from the default, and so
# the summary can say which branch actually shipped.
readonly DEFAULT_BRANCH="main"
BRANCH="$DEFAULT_BRANCH"

# The existing nightly backup script. We reuse it rather than reimplementing the
# WAL-aware snapshot + integrity check — it is already the trusted path.
readonly BACKUP_SCRIPT="$HOME/scripts/backup-simpleblog.sh"

# Where that script writes. It names its output db-<YYYY-MM-DD>.sqlite, i.e. date
# only — so a second run on the same day OVERWRITES the first. That is why the
# deploy takes its own separate -predeploy copy below rather than relying on the
# nightly file still being there after the next nightly run.
readonly BACKUP_DIR="$HOME/backups/simpleblog"

# The systemd --user unit that serves the site.
readonly SERVICE="simple-blog.service"

# Verification target. Deliberately the PUBLIC URL, not http://127.0.0.1:8001.
# `php artisan serve` rejects a request whose Host header does not match and
# returns 400, so a localhost check would fail on a perfectly healthy deploy.
# Going through Caddy also exercises what a visitor actually gets: TLS, the
# reverse proxy, and the app together. Measured behaviour: healthy site returns
# 200; app stopped returns 502 from Caddy.
readonly VERIFY_URL="https://simpleblog.brianjgoodwin.dev/"

# Restart readiness. The unit is Type=simple, so systemd reports it active the
# instant the process spawns — well before PHP is listening. Measured ready time
# on this box is ~720ms, so poll rather than sleeping a fixed pessimistic amount.
readonly VERIFY_ATTEMPTS=20
readonly VERIFY_INTERVAL=0.5
readonly VERIFY_TIMEOUT=5

# Serialises deploys against each other. Pulled forward from a later phase: the
# nightly backup timer fires at 03:30, and without a lock a deploy running in
# that window cannot tell its own fresh snapshot from the timer's — the two are
# minutes apart and both match the freshness check. Benign today, but not once
# migrations run against the DB between snapshot and deploy.
#
# NOTE: backup-simpleblog.sh does not yet take this lock, so a deploy and the
# nightly timer can still overlap. Making the backup script take it too is
# tracked as follow-up work; this half stops two deploys colliding.
readonly LOCKFILE="$HOME/.cache/simpleblog-deploy.lock"

# --- Output helper -----------------------------------------------------------

# log — timestamped, so the output doubles as a deploy record.
log() {
    printf '%s  %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"
}

# die — print an error to stderr and exit non-zero. Used for setup/precondition
# failures. (The richer stop-and-alert recovery reporting arrives in Phase 6.)
die() {
    printf '%s  ERROR: %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >&2
    exit 1
}

# --- Usage -------------------------------------------------------------------

usage() {
    cat <<'EOF'
Usage: deploy.sh [--check | --dry-run] [--branch <name>]
       deploy.sh --help

  (no flag)       Perform a full deploy: verified DB backup, fast-forward pull,
                  conditional composer/migrate/build, restart, and verify 200.
  --check         Report whether the live checkout has drifted behind the remote.
                  Read-only: changes nothing.
  --dry-run       Show what a real deploy WOULD do, without doing it.
                  Read-only: changes nothing.
  --branch <name> Deploy from a branch other than main. For drills and testing
                  only — see docs/migrate-drill.md. Prints a warning, and the
                  branch must already exist on the remote.
  --help          Show this help.

The live checkout is /srv/www/simpleblog; it deploys from origin/main by default.
EOF
}

# --- Argument parsing --------------------------------------------------------

# One mode per run; --branch may accompany any of them.
MODE="deploy"
MODE_SET="no"   # so a second mode flag is an error rather than silently winning

parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --check|--dry-run)
                [[ "$MODE_SET" == "no" ]] \
                    || die "pick one mode: --check or --dry-run, not both"
                MODE="${1#--}"
                MODE_SET="yes"
                shift
                ;;
            --branch)
                # Requires a value. Without this guard, `--branch` as the last
                # argument would consume nothing and silently deploy main.
                [[ $# -ge 2 ]] || die "--branch requires a branch name"
                BRANCH="$2"
                [[ -n "$BRANCH" ]] || die "--branch requires a non-empty branch name"
                # Reject anything that isn't a plausible ref name. The value is
                # interpolated into git refs like "$REMOTE/$BRANCH", so keep it
                # to a conservative character set rather than trusting the caller.
                [[ "$BRANCH" =~ ^[A-Za-z0-9._/-]+$ ]] \
                    || die "invalid branch name: '$BRANCH'"
                [[ "$BRANCH" != -* ]] \
                    || die "invalid branch name: '$BRANCH' (looks like a flag)"
                shift 2
                ;;
            --help|-h)
                usage
                exit 0
                ;;
            *)
                usage >&2
                die "unknown argument: $1"
                ;;
        esac
    done
}

# --- Preconditions -----------------------------------------------------------

# assert_app_dir — the live checkout must exist and be a git repo before we can
# inspect anything. cd into it so all later git commands operate on it.
assert_app_dir() {
    [[ -d "$APP_DIR" ]] || die "app directory not found: $APP_DIR"
    cd "$APP_DIR" || die "cannot cd into $APP_DIR"
    git rev-parse --is-inside-work-tree >/dev/null 2>&1 \
        || die "$APP_DIR is not a git working tree"
}

# warn_if_not_default_branch — deploying anything but main is a deliberate,
# unusual act (drills, testing). Say so loudly and unmissably, every time, so it
# can never happen by accident or go unnoticed in a scrollback.
warn_if_not_default_branch() {
    [[ "$BRANCH" != "$DEFAULT_BRANCH" ]] || return 0

    log "!! ---------------------------------------------------------------"
    log "!! NON-DEFAULT BRANCH: deploying '$BRANCH', not '$DEFAULT_BRANCH'."
    log "!! This is for drills and testing. If you did not intend it, stop now."
    log "!! ---------------------------------------------------------------"
}

# assert_branch_exists — fail early and clearly if the requested branch is not on
# the remote. Without this the first symptom is a confusing error from
# rev-parse deep inside compute_drift.
assert_branch_exists() {
    git ls-remote --exit-code --heads "$REMOTE" "$BRANCH" >/dev/null 2>&1 \
        || die "branch '$BRANCH' does not exist on $REMOTE"
}

# --- Drift computation (shared, read-only) -----------------------------------

# These globals are populated by compute_drift() and read by the modes.
OLD_SHA=""          # current HEAD of the live checkout
NEW_SHA=""          # tip of the remote branch we'd deploy to
COMMITS_BEHIND=""   # how many commits HEAD is behind the remote tip
DIVERGED="no"       # "yes" if HEAD is not an ancestor of the remote tip

# compute_drift — fetch the remote and work out the relationship between the
# live checkout and origin/main. Read-only: git fetch updates remote-tracking
# refs only, it does not touch the working tree or current branch.
compute_drift() {
    log "Fetching $REMOTE/$BRANCH ..."
    git fetch --quiet "$REMOTE" "$BRANCH" || die "git fetch failed"

    OLD_SHA="$(git rev-parse --short HEAD)"
    NEW_SHA="$(git rev-parse --short "$REMOTE/$BRANCH")"
    COMMITS_BEHIND="$(git rev-list --count "HEAD..$REMOTE/$BRANCH")"

    # If HEAD is NOT an ancestor of the remote tip, the branches have diverged
    # (local commits the remote doesn't have) — a fast-forward is impossible and
    # a deploy must refuse rather than guess.
    if git merge-base --is-ancestor HEAD "$REMOTE/$BRANCH"; then
        DIVERGED="no"
    else
        DIVERGED="yes"
    fi
}

# --- Conditional-work decisions (shared, read-only) --------------------------
#
# These decide which of composer / migrate / build a real deploy WOULD run,
# purely from the diff between the live HEAD (OLD_SHA) and the remote tip
# (NEW_SHA). No database query and no working-tree change — so the same logic is
# safe to run in --dry-run and, later, to drive the real deploy.
#
# Each returns "run" or "skip" via a global, plus a human reason. compute_drift()
# must have run first (it sets OLD_SHA / NEW_SHA and the remote-tracking ref).

DO_COMPOSER=""   ; REASON_COMPOSER=""
DO_MIGRATE=""    ; REASON_MIGRATE=""
DO_BUILD=""      ; REASON_BUILD=""

# _paths_changed — did the incoming commits touch any of the given paths?
# Uses the remote-tracking ref (not the un-pulled working tree), so it reflects
# exactly what the pull would bring in. Returns 0 (yes) / 1 (no). A git failure
# aborts the script via die() rather than being silently read as "no change" —
# a swallowed error must never masquerade as "nothing to do".
_paths_changed() {
    local changed
    changed="$(git diff --name-only "HEAD..$REMOTE/$BRANCH" -- "$@")" \
        || die "git diff failed while checking paths: $*"
    [[ -n "$changed" ]]
}

# _migrations_added — do the incoming commits ADD any migration files? New files
# under database/migrations/ are the honest dry-run predictor of a pending
# migration. (The real deploy path re-checks `migrate:status` AFTER pulling,
# since that is the true source of what's pending — see Phase 4.)
# --no-renames: a re-timestamped/reordered migration is a rename to git; without
# this it would show as R (not A) and be missed — a false "skip" on migrate.
_migrations_added() {
    local added
    added="$(git diff --name-only --no-renames --diff-filter=A "HEAD..$REMOTE/$BRANCH" -- database/migrations/)" \
        || die "git diff failed while checking for new migrations"
    [[ -n "$added" ]]
}

compute_decisions() {
    if _paths_changed composer.lock; then
        DO_COMPOSER="run";  REASON_COMPOSER="composer.lock changed in the incoming commits"
    else
        DO_COMPOSER="skip"; REASON_COMPOSER="composer.lock unchanged"
    fi

    if _migrations_added; then
        DO_MIGRATE="run";  REASON_MIGRATE="new migration file(s) in the incoming commits"
    else
        DO_MIGRATE="skip"; REASON_MIGRATE="no new migration files incoming"
    fi

    # Watching resources/ alone is not enough: a Tailwind or Vite config change,
    # or a JS dependency bump, changes the built output just as surely and would
    # otherwise ship stale assets with no warning.
    if _paths_changed resources/ package.json package-lock.json \
                      vite.config.js tailwind.config.js postcss.config.js; then
        DO_BUILD="run";  REASON_BUILD="assets, build config or JS dependencies changed"
    else
        DO_BUILD="skip"; REASON_BUILD="nothing affecting the asset build changed"
    fi
}

# --- Deploy steps (mutating) -------------------------------------------------
#
# Everything below this line can change the live site. Each step is written to
# fail loudly: on any error the script exits non-zero via die() or errexit,
# leaving the site in whatever state the last completed step produced. It never
# attempts an automatic rollback (resolved decision #2) — see the recovery notes
# printed on failure in Phase 6.

# Populated by run_backup() so later steps and the failure report can name the
# snapshot the operator would restore from.
SNAPSHOT=""
# Identity of that snapshot (inode, size, mtime) captured at creation, so the
# irreversible steps can prove the file behind them is still the same one.
SNAPSHOT_ID=""

# Value of $SECONDS when the mutating work began, for the elapsed-time line in
# the summary. ($SECONDS is a bash builtin counting from shell start.)
DEPLOY_START=0

# assert_clean_tree — refuse to deploy over local modifications. A dirty live
# checkout means someone edited files on the server; pulling on top of that
# either fails or silently buries their work. Either way a human should look
# first. --porcelain prints one line per change and nothing at all when clean.
# Untracked files count: they can collide with incoming files and block the pull.
assert_clean_tree() {
    local dirty
    dirty="$(git status --porcelain)" || die "git status failed"
    if [[ -n "$dirty" ]]; then
        log "Working tree is NOT clean:"
        printf '%s\n' "$dirty"
        die "refusing to deploy over local changes in $APP_DIR; commit, stash, or remove them first"
    fi
}

# assert_deployable — the preconditions that make a deploy safe to start. Called
# after compute_drift() has populated the drift globals.
assert_deployable() {
    # A detached HEAD passes the clean-tree check (porcelain is empty) and would
    # pass the post-pull SHA check too, because the pull really does move HEAD.
    # But it moves the DETACHED head, leaving refs/heads/main behind — a deploy
    # that reports success while silently corrupting the branch state. Assert we
    # are on a branch, and on the right one.
    #
    # Note this deliberately does NOT switch branches for you. With --branch, the
    # live checkout must already be on the branch you name. Having a deploy
    # script silently `git checkout` the live site is a far worse failure mode
    # than making you do it by hand and think about it.
    local current_branch
    current_branch="$(git symbolic-ref --quiet --short HEAD)" \
        || die "live checkout is in detached HEAD state; run 'git -C $APP_DIR checkout $BRANCH' first"
    if [[ "$current_branch" != "$BRANCH" ]]; then
        log "Live checkout is on '$current_branch' but the deploy targets '$BRANCH'."
        log "This script will not switch branches for you. To proceed deliberately:"
        log "  git -C $APP_DIR checkout $BRANCH"
        die "branch mismatch; refusing to deploy"
    fi

    if [[ "$DIVERGED" == "yes" ]]; then
        log "DIVERGED: local HEAD ($OLD_SHA) is not an ancestor of $REMOTE/$BRANCH ($NEW_SHA)."
        log "The live checkout has commits the remote does not. A fast-forward is impossible."
        die "refusing to deploy a diverged checkout; reconcile $APP_DIR with $REMOTE/$BRANCH by hand"
    fi

    if ! [[ "$COMMITS_BEHIND" =~ ^[0-9]+$ ]]; then
        die "internal error: commit count is not a number: '$COMMITS_BEHIND'"
    fi
}

# run_backup — take a verified DB snapshot BEFORE any pull or migration.
#
# Two files result:
#   1. The nightly script's own dated file (db-YYYY-MM-DD.sqlite). It does the
#      WAL-aware .backup and the integrity check, and refuses to prune if the
#      check fails — so a non-zero exit here means DO NOT PROCEED.
#   2. A -predeploy copy stamped to the second. The dated file is overwritten by
#      the next nightly run (and by any same-day deploy); this copy is the one
#      that is still around tomorrow when you need it.
run_backup() {
    log "Step: backup (verified snapshot before any change)"

    [[ -x "$BACKUP_SCRIPT" ]] \
        || die "backup script not found or not executable: $BACKUP_SCRIPT"

    # Runs the integrity check internally; a non-zero exit means the snapshot is
    # untrustworthy. errexit would catch this, but be explicit — this is the step
    # whose failure must absolutely stop the deploy.
    "$BACKUP_SCRIPT" || die "backup failed; NOT proceeding (no verified snapshot, so no safety net)"

    # Find the file the backup just wrote: the newest dated snapshot. This
    # sidesteps having to predict the name the backup script chose (it reads its
    # own clock, so a run near midnight can land on either date). `ls -t` sorts
    # newest-first.
    #
    # The anchored glob is load-bearing, not cosmetic — do not loosen it to
    # db-*.sqlite. Two reasons: (a) it excludes -predeploy copies, which cp -p
    # gives a preserved mtime that could otherwise win the -t sort; (b) bash
    # expands it into separate argv entries, so `ls` never parses a filename and
    # a name containing whitespace or a newline cannot match the fixed date shape.
    local dated
    dated="$(ls -t "$BACKUP_DIR"/db-[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9].sqlite 2>/dev/null | head -1)" \
        || die "could not list $BACKUP_DIR"
    [[ -n "$dated" && -f "$dated" ]] \
        || die "backup reported success but no snapshot found in $BACKUP_DIR; refusing to proceed"

    # Confirm it really is from this run, not a stale file from days ago. If the
    # backup script somehow exited 0 without writing, we must not mistake an old
    # snapshot for a fresh safety net.
    #
    # Phrased as "prove it is fresh, else die" rather than "prove it is stale,
    # else proceed". The difference matters: `find` on a missing or unreadable
    # path exits non-zero but prints NOTHING, so a stale-detecting test would
    # read that empty output as "not stale" and let the deploy continue without
    # a safety net. With -mmin -5 an error, a missing file, and a stale file all
    # produce empty output, and all three stop the deploy.
    local fresh
    fresh="$(find "$dated" -mmin -5 -print 2>/dev/null)" \
        || die "could not stat snapshot $dated; refusing to proceed"
    [[ -n "$fresh" ]] \
        || die "snapshot $dated is not from this run (not modified in the last 5 minutes); refusing to proceed"

    SNAPSHOT="$BACKUP_DIR/db-$(date '+%Y-%m-%d-%H%M%S')-predeploy.sqlite"
    # install, not cp -p + chmod: it creates the file at mode 600 directly, so
    # the snapshot (which contains password hashes) is never briefly readable at
    # a wider mode. Not preserving mtime is deliberate — it keeps -predeploy
    # copies from ever competing in the `ls -t` selection above.
    install -m 600 "$dated" "$SNAPSHOT" \
        || die "could not create pre-deploy snapshot at $SNAPSHOT"

    # Record the snapshot's identity so the irreversible steps can prove they are
    # still protected by THIS file. "Under five minutes old" is a heuristic that
    # happens to be true of any recent snapshot, including one the nightly timer
    # wrote moments earlier; inode + size + mtime identifies one specific file.
    # Checked again by assert_snapshot_intact() immediately before migrate runs.
    SNAPSHOT_ID="$(stat -c '%i %s %.9Y' "$SNAPSHOT")" \
        || die "could not stat the pre-deploy snapshot at $SNAPSHOT"

    log "  snapshot: $SNAPSHOT"
}

# assert_snapshot_intact — re-verify the snapshot right before an irreversible
# step. Called immediately ahead of migrate in Phase 4.
#
# Guards the window between taking the snapshot and using it: a concurrent prune,
# a full disk, or a stray process could remove or rewrite the file, and a
# migration that runs with no restorable snapshot behind it is the one situation
# this whole script exists to prevent.
assert_snapshot_intact() {
    [[ -n "$SNAPSHOT" && -n "$SNAPSHOT_ID" ]] \
        || die "internal error: no snapshot recorded before an irreversible step"
    [[ -f "$SNAPSHOT" ]] \
        || die "pre-deploy snapshot $SNAPSHOT has disappeared; refusing to continue"

    local now
    now="$(stat -c '%i %s %.9Y' "$SNAPSHOT")" \
        || die "could not re-stat the pre-deploy snapshot $SNAPSHOT"
    [[ "$now" == "$SNAPSHOT_ID" ]] \
        || die "pre-deploy snapshot $SNAPSHOT changed since it was taken (was [$SNAPSHOT_ID], now [$now]); refusing to continue"
}

# run_pull — fast-forward the live checkout to the remote tip.
#
# --ff-only is the safety property: if a fast-forward is not possible git exits
# non-zero and changes nothing, rather than creating a merge commit on the live
# box. assert_deployable() already rejects the diverged case with a clearer
# message; this flag is the backstop if state changed since that check.
run_pull() {
    log "Step: pull ($OLD_SHA -> $NEW_SHA, fast-forward only)"
    git pull --ff-only "$REMOTE" "$BRANCH" \
        || die "git pull --ff-only failed; the checkout is unchanged at $OLD_SHA"

    local now
    now="$(git rev-parse --short HEAD)" || die "could not read HEAD after pull"
    [[ "$now" == "$NEW_SHA" ]] \
        || die "post-pull HEAD is $now but expected $NEW_SHA; stopping before any further change"
    log "  now at: $now"
}

# run_composer — refresh PHP dependencies, only when composer.lock changed.
#
# --no-dev: the live box never runs the test suite (resolved decision — Pest
# would truncate the production database), so dev dependencies are dead weight
# and extra supply-chain surface.
# --optimize-autoloader: builds a classmap, worth it for a long-lived process.
# --no-interaction: this may run non-interactively; never block on a prompt.
run_composer() {
    if [[ "$DO_COMPOSER" != "run" ]]; then
        log "Step: composer — SKIPPED ($REASON_COMPOSER)"
        return 0
    fi

    log "Step: composer install ($REASON_COMPOSER)"
    composer install --no-dev --optimize-autoloader --no-interaction \
        || die "composer install failed; dependencies may be in a partial state"
}

# run_migrate — apply pending migrations. THE irreversible step.
#
# Two deliberate choices:
#
# 1. The decision to run comes from `migrate:status` here, NOT from the git diff
#    that drove --dry-run. Before the pull, incoming migration files are the only
#    honest predictor; after it, Laravel's own ledger is ground truth — it knows
#    about migrations pending for reasons unrelated to this deploy.
# 2. assert_snapshot_intact() runs immediately before, not merely earlier in the
#    deploy. This is the last moment a snapshot can still save you.
#
# --force is required because APP_ENV=production; it suppresses the interactive
# "are you sure" prompt, not any safety check.
run_migrate() {
    # Re-read from Laravel now that the new migration files are actually on disk.
    # A failure here must not be read as "nothing pending" — if we cannot tell,
    # we stop rather than skip the one step whose omission ships broken code.
    local pending
    pending="$(php artisan migrate:status --pending 2>&1)" \
        || die "could not read migration status after the pull; refusing to guess whether migrations are pending"

    # Verified against this Laravel version: with nothing pending the command
    # still exits 0 and prints "INFO  No pending migrations."; with something
    # pending it prints a table of migration names marked Pending. So emptiness
    # is NOT the signal — match the explicit no-pending message instead.
    if printf '%s' "$pending" | grep -qi 'No pending migrations'; then
        log "Step: migrate — SKIPPED (Laravel reports no pending migrations after the pull)"
        return 0
    fi

    # Anything unrecognised is treated as "there might be work", which fails
    # toward running a no-op migrate rather than silently skipping a real one.
    if ! printf '%s' "$pending" | grep -qi 'pending'; then
        log "Step: migrate — unrecognised migrate:status output, treating as pending:"
        printf '%s\n' "$pending" | sed 's/^/    /'
    fi

    log "Step: migrate (pending migrations found after the pull)"
    log "  pending:"
    printf '%s\n' "$pending" | sed 's/^/    /'

    # The snapshot is the only way back from here. Prove it is still the exact
    # file this run created before touching the schema.
    assert_snapshot_intact
    log "  snapshot verified intact: $SNAPSHOT"

    php artisan migrate --force \
        || die "migrate FAILED; the schema may be partially applied. Restore $SNAPSHOT (see docs/migrate-drill.md) — do NOT rely on migrate:rollback"
}

# run_build — rebuild front-end assets, when anything affecting them changed.
#
# Runs after migrate so a schema failure stops the deploy before spending a
# minute on Vite. Ordering within the mutating middle is otherwise arbitrary,
# but backup-before-migrate is not — that one is the whole safety story.
#
# Three steps, in this order: compile every template (so Tailwind sees them all),
# install exactly the lockfile, then build.
run_build() {
    if [[ "$DO_BUILD" != "run" ]]; then
        log "Step: build — SKIPPED ($REASON_BUILD)"
        return 0
    fi

    # Compile every Blade template before building. Tailwind's content config
    # scans the compiled-view directory, so without this it sees only whichever
    # views happened to be cached and silently omits classes used elsewhere —
    # measured at ~1KB of missing CSS on this app. view:cache makes the input
    # complete and identical on every run.
    log "Step: view:cache (populate compiled views so Tailwind sees every template)"
    php artisan view:cache \
        || die "view:cache failed; refusing to build assets from an incomplete template set"

    # Install exactly the lockfile before building. Without this the build uses
    # whatever node_modules a past manual install happened to leave behind, so a
    # dependency bump in package-lock.json would produce assets built from the
    # OLD tree — the same silent drift this whole script exists to prevent.
    #
    # `ci` not `install`: it installs the lockfile exactly and errors if
    # package.json and the lock disagree, rather than quietly resolving.
    # --ignore-scripts is the supply-chain boundary: install-time lifecycle
    # scripts are the vector in most real npm compromises. .npmrc already sets
    # it, but stating it here means the boundary does not depend on a config
    # file that a stray `npm config set` could change.
    log "Step: npm ci (install exactly package-lock.json)"
    npm ci --ignore-scripts \
        || die "npm ci failed; node_modules may be in a partial state"

    log "Step: npm run build ($REASON_BUILD)"
    npm run build \
        || die "npm run build failed; public/build may hold a half-written manifest"
}

# run_optimize_clear — drop Laravel's compiled caches so the new code is what
# actually runs. Unconditional: cheap, and the failure it prevents (stale
# compiled views or config surviving a deploy) is confusing to diagnose.
run_optimize_clear() {
    log "Step: optimize:clear"
    php artisan optimize:clear \
        || die "optimize:clear failed; the app may still be serving cached views or config"

    # optimize:clear includes view:clear, which empties the very directory
    # tailwind.config.js scans. Leaving it empty means the NEXT deploy's build
    # starts from an incomplete template set — the ~1KB-of-missing-CSS bug again.
    # Repopulating here also means the app serves precompiled views rather than
    # compiling each one on its first request.
    log "  repopulating the compiled view cache"
    php artisan view:cache \
        || die "view:cache failed after optimize:clear; the next build would see an incomplete template set"
}

# run_restart — restart the service so the new code is actually running.
#
# Unconditional: PHP's built-in server holds compiled state in the process, so
# skipping the restart is how you get a deploy that reports success while the
# old code keeps serving.
run_restart() {
    log "Step: restart $SERVICE"
    systemctl --user restart "$SERVICE" \
        || die "failed to restart $SERVICE; the site may be DOWN — check 'systemctl --user status $SERVICE'"

    # is-active is necessary but nowhere near sufficient (Type=simple reports
    # active immediately). The HTTP check below is what actually proves anything.
    local state
    state="$(systemctl --user is-active "$SERVICE" 2>/dev/null)" || state="unknown"
    log "  unit state: $state"
    [[ "$state" == "active" ]] \
        || die "$SERVICE is '$state' after restart; the site is probably DOWN"
}

# run_verify — prove the site actually serves before declaring success.
#
# This is the step that makes "the deploy finished" and "the deploy worked" the
# same claim. It retries because the restart above returns before PHP is ready;
# a single immediate curl would fail on a healthy deploy.
run_verify() {
    log "Step: verify $VERIFY_URL"

    local attempt code
    for (( attempt = 1; attempt <= VERIFY_ATTEMPTS; attempt++ )); do
        # curl's own exit status is ignored on purpose: a connection refused
        # mid-warmup is expected, and %{http_code} is 000 in that case, which
        # simply fails the comparison below and retries.
        code="$(curl -s -o /dev/null -w '%{http_code}' --max-time "$VERIFY_TIMEOUT" "$VERIFY_URL" 2>/dev/null)" || code="000"

        if [[ "$code" == "200" ]]; then
            log "  HTTP 200 after $attempt attempt(s)"
            return 0
        fi

        sleep "$VERIFY_INTERVAL"
    done

    # Out of attempts. Say what was seen — 502 means Caddy is up but the app is
    # not answering, which points somewhere different than a 500 from the app.
    log "  last HTTP status: $code"
    die "verification FAILED: $VERIFY_URL did not return 200 after $VERIFY_ATTEMPTS attempts. The site may be serving errors — snapshot: $SNAPSHOT, previous commit: $OLD_SHA"
}

# --- Modes -------------------------------------------------------------------

# mode_check — report drift and exit. Changes nothing.
mode_check() {
    compute_drift

    if [[ "$DIVERGED" == "yes" ]]; then
        log "DIVERGED: local HEAD ($OLD_SHA) is not an ancestor of $REMOTE/$BRANCH ($NEW_SHA)."
        log "A deploy would REFUSE this state (no fast-forward possible)."
        return 0
    fi

    # Guard the arithmetic: an empty or non-numeric count must fail loudly, not
    # silently read as "up to date". [[ "" -eq 0 ]] is true, which would be a
    # dangerous wrong answer for a deploy tool.
    if ! [[ "$COMMITS_BEHIND" =~ ^[0-9]+$ ]]; then
        die "internal error: commit count is not a number: '$COMMITS_BEHIND'"
    fi

    if (( COMMITS_BEHIND == 0 )); then
        log "Up to date: live ($OLD_SHA) matches $REMOTE/$BRANCH."
    else
        log "Behind: live ($OLD_SHA) is $COMMITS_BEHIND commit(s) behind $REMOTE/$BRANCH ($NEW_SHA)."
        log "Incoming commits:"
        # --no-pager: never invoke less; this must not block, especially non-interactively.
        git --no-pager log --oneline "HEAD..$REMOTE/$BRANCH"
    fi
}

# mode_dry_run — report exactly what a real deploy WOULD do, and do none of it.
mode_dry_run() {
    compute_drift

    if [[ "$DIVERGED" == "yes" ]]; then
        log "DIVERGED: local HEAD ($OLD_SHA) is not an ancestor of $REMOTE/$BRANCH ($NEW_SHA)."
        log "A real deploy would REFUSE this state. Nothing else to plan."
        return 0
    fi

    if ! [[ "$COMMITS_BEHIND" =~ ^[0-9]+$ ]]; then
        die "internal error: commit count is not a number: '$COMMITS_BEHIND'"
    fi

    if (( COMMITS_BEHIND == 0 )); then
        log "Up to date: live ($OLD_SHA) matches $REMOTE/$BRANCH. A deploy would do nothing."
        return 0
    fi

    log "DRY RUN — planning a deploy of $COMMITS_BEHIND commit(s), $OLD_SHA -> $NEW_SHA. Nothing will change."
    log "Incoming commits:"
    git --no-pager log --oneline "HEAD..$REMOTE/$BRANCH"

    compute_decisions
    log "Planned steps:"
    log "  backup      : would run  (always — verified snapshot before any migrate)"
    log "  pull        : would run  (fast-forward to $NEW_SHA)"
    log "  composer    : would ${DO_COMPOSER}  ($REASON_COMPOSER)"
    log "  migrate     : would ${DO_MIGRATE}  ($REASON_MIGRATE)"
    log "  build       : would ${DO_BUILD}  ($REASON_BUILD)"
    log "  optimize:clear, restart, verify : would run"
    log "DRY RUN complete. No changes were made."
}

# take_lock — refuse to run if another deploy holds the lock.
#
# fd 9 is held for the lifetime of the script; the kernel releases the lock when
# the process exits, however it exits, so there is no stale-lock cleanup to get
# wrong. -n means fail immediately rather than block: a deploy that silently
# waits is worse than one that tells you to look at what else is running.
take_lock() {
    mkdir -p "$(dirname "$LOCKFILE")" || die "cannot create lock directory for $LOCKFILE"
    exec 9>"$LOCKFILE" || die "cannot open lock file $LOCKFILE"
    flock -n 9 || die "another deploy is already running (lock: $LOCKFILE); refusing to start a second"
}

# mode_deploy — the real thing, end to end: preconditions, backup, pull, the
# conditional middle, restart, and verification that the site actually serves.
mode_deploy() {
    DEPLOY_START=$SECONDS
    take_lock
    assert_clean_tree
    compute_drift
    assert_deployable

    if (( COMMITS_BEHIND == 0 )); then
        log "Up to date: live ($OLD_SHA) already matches $REMOTE/$BRANCH. Nothing to deploy."
        return 0
    fi

    log "Deploying $COMMITS_BEHIND commit(s), $OLD_SHA -> $NEW_SHA."
    log "Incoming commits:"
    git --no-pager log --oneline "HEAD..$REMOTE/$BRANCH"

    # Decide what the pull implies BEFORE pulling — the diff against the remote
    # ref is only meaningful while HEAD is still the old commit.
    compute_decisions

    # Order is the safety story, not a style choice:
    #   backup  — before anything irreversible, so there is always a way back
    #   pull    — new code on disk before anything reads it
    #   composer— dependencies before code that might import them
    #   migrate — schema after its code arrives; the irreversible step
    #   clear   — drop stale compiled caches from the old code
    #   build   — assets last of the mutating work; slow and easily redone
    #
    # The clear/build ordering here is not arbitrary. tailwind.config.js scans
    # './storage/framework/views/*.php' — the compiled Blade cache — so the CSS
    # Tailwind emits depends on which views are compiled when it runs. Measured
    # on this app: an empty or partial cache yields 43577 bytes of CSS, while a
    # fully compiled one yields 44542. That ~1KB is utility classes used only by
    # templates that were never compiled, and their absence means those pages
    # render with styles missing.
    #
    # So the order is: clear the stale cache, recompile ALL views from the new
    # code, then build against that complete input. run_build() does the
    # recompile itself so the two stay together.
    run_backup
    run_pull
    run_composer
    run_migrate
    run_optimize_clear
    run_build

    # Restart last, then prove it works. Everything above changed files on disk;
    # until the service restarts, the running process is still the old code.
    run_restart
    run_verify

    print_summary
}

# print_summary — the final record. Deliberately restates what ran and what was
# skipped: a silent skip that looks like success is the failure mode this whole
# script was written to avoid.
print_summary() {
    local subject elapsed
    subject="$(git --no-pager log -1 --format='%s' HEAD 2>/dev/null)" || subject="(unknown)"
    elapsed=$(( SECONDS - DEPLOY_START ))

    log "-------------------------------------------------------------"
    log "DEPLOY OK — $VERIFY_URL returned 200"
    log "  branch       : $BRANCH"
    log "  shipped      : $NEW_SHA  $subject"
    log "  previous     : $OLD_SHA  (restore point)"
    log "  commits      : $COMMITS_BEHIND"
    log "  composer     : $DO_COMPOSER  ($REASON_COMPOSER)"
    log "  migrate      : see the migrate step above (decided from migrate:status after the pull)"
    log "  build        : $DO_BUILD  ($REASON_BUILD)"
    log "  snapshot     : $SNAPSHOT"
    log "  elapsed      : ${elapsed}s"
    log "-------------------------------------------------------------"
}

# --- Main --------------------------------------------------------------------

main() {
    parse_args "$@"
    assert_app_dir
    warn_if_not_default_branch
    assert_branch_exists

    case "$MODE" in
        check)   mode_check ;;
        dry-run) mode_dry_run ;;
        deploy)  mode_deploy ;;
        *)       die "internal error: unknown mode '$MODE'" ;;
    esac
}

main "$@"
