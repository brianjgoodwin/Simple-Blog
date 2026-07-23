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
# Built so far (Phases 1-3): skeleton, strict mode, arg parsing, --check and
# --dry-run (both read-only), and the first half of the real deploy — verified DB
# backup plus a fast-forward-only pull. Still to come: conditional composer /
# migrate / build, service restart, HTTP verification, and locking.

set -euo pipefail

# --- Constants ---------------------------------------------------------------

# The LIVE checkout. NOT ~/developer/simple-blog (that's a separate dev copy with
# its own database). Everything this script does targets the served checkout.
readonly APP_DIR="/srv/www/simpleblog"

# The branch we deploy from, and its remote.
readonly REMOTE="origin"
readonly BRANCH="main"

# The existing nightly backup script. We reuse it rather than reimplementing the
# WAL-aware snapshot + integrity check — it is already the trusted path.
readonly BACKUP_SCRIPT="$HOME/scripts/backup-simpleblog.sh"

# Where that script writes. It names its output db-<YYYY-MM-DD>.sqlite, i.e. date
# only — so a second run on the same day OVERWRITES the first. That is why the
# deploy takes its own separate -predeploy copy below rather than relying on the
# nightly file still being there after the next nightly run.
readonly BACKUP_DIR="$HOME/backups/simpleblog"

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
Usage: deploy.sh [--check | --dry-run | --help]

  (no flag)   Perform a deploy: verified DB backup, then fast-forward pull.
              [partial — restart/build/migrate arrive in later phases]
  --check     Report whether the live checkout has drifted behind the remote.
              Read-only: changes nothing.
  --dry-run   Show what a real deploy WOULD do, without doing it.
              Read-only: changes nothing.
  --help      Show this help.

The live checkout is /srv/www/simpleblog; it deploys from origin/main.
EOF
}

# --- Argument parsing --------------------------------------------------------

# Exactly one mode. Default (empty) means "real deploy", which isn't wired up
# yet, so for now it's rejected with a clear message rather than doing nothing.
MODE="deploy"

parse_args() {
    # Reject extra args first, so the "at most one flag" rule holds uniformly —
    # including for --help, which would otherwise exit before this check.
    if [[ $# -gt 1 ]]; then
        usage >&2
        die "too many arguments; expected at most one flag"
    fi

    case "${1:-}" in
        --check)   MODE="check" ;;
        --dry-run) MODE="dry-run" ;;
        --help|-h) usage; exit 0 ;;
        "")        MODE="deploy" ;;
        *)         usage >&2; die "unknown argument: $1" ;;
    esac
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

    if _paths_changed resources/; then
        DO_BUILD="run";  REASON_BUILD="files under resources/ changed"
    else
        DO_BUILD="skip"; REASON_BUILD="nothing under resources/ changed"
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

    # Recompute the nightly script's output path rather than parsing its stdout.
    # Same date format, so the same filename — and a literal string comparison is
    # easier to reason about than scraping a log line.
    #
    # Find the file the backup just wrote: the newest dated snapshot. This
    # sidesteps having to predict the name the backup script chose (it reads its
    # own clock, so a run near midnight can land on either date). The glob is
    # anchored to the 10-char date form so it cannot match a -predeploy copy
    # left by an earlier deploy. `ls -t` sorts newest-first.
    local dated
    dated="$(ls -t "$BACKUP_DIR"/db-[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9].sqlite 2>/dev/null | head -1)" \
        || die "could not list $BACKUP_DIR"
    [[ -n "$dated" && -f "$dated" ]] \
        || die "backup reported success but no snapshot found in $BACKUP_DIR; refusing to proceed"

    # Confirm it really is from this run, not a stale file from days ago. If the
    # backup script somehow exited 0 without writing, we must not mistake an old
    # snapshot for a fresh safety net.
    if [[ -n "$(find "$dated" -mmin +5)" ]]; then
        die "newest snapshot $dated is older than 5 minutes; the backup did not write a fresh file — refusing to proceed"
    fi

    SNAPSHOT="$BACKUP_DIR/db-$(date '+%Y-%m-%d-%H%M%S')-predeploy.sqlite"
    cp -p "$dated" "$SNAPSHOT" || die "could not create pre-deploy snapshot at $SNAPSHOT"
    chmod 600 "$SNAPSHOT" || die "could not restrict permissions on $SNAPSHOT"

    log "  snapshot: $SNAPSHOT"
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

# mode_deploy — the real thing. Phase 3 covers preconditions, backup and pull;
# the remaining steps arrive in later phases.
mode_deploy() {
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

    run_backup
    run_pull

    log "Phase 3 complete: backup taken and code updated to $NEW_SHA."
    log "  snapshot     : $SNAPSHOT"
    log "  previous SHA : $OLD_SHA  (restore point)"
    log "Still pending (later phases): composer=${DO_COMPOSER}, migrate=${DO_MIGRATE}, build=${DO_BUILD}, optimize:clear, restart, verify."
    log "The service has NOT been restarted and assets have NOT been rebuilt — it is still serving the old build."
}

# --- Main --------------------------------------------------------------------

main() {
    parse_args "$@"
    assert_app_dir

    case "$MODE" in
        check)   mode_check ;;
        dry-run) mode_dry_run ;;
        deploy)  mode_deploy ;;
        *)       die "internal error: unknown mode '$MODE'" ;;
    esac
}

main "$@"
