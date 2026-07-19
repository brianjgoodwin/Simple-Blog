# Simple Blog — Build Log & Decision History

The chronological record of what was built and *why*. The forward-looking
design and roadmap live in [PLAN.md](PLAN.md); this file is the history that
plan steered against — kept because the reasoning behind a shipped decision is
worth as much as the decision.

Phases were sized as coherent, testable chunks. Dates and commit hashes are
recorded where known. Everything here is **built and shipped** unless noted.

---

## Build phases

### Phase 0 — Scaffold
`laravel new`, Breeze (Blade), SQLite, first migration + run. Clean baseline;
login working.

### Phase 1 — Users & accounts
Added immutable `username` to users. `author:create` artisan command creates a
user and seeds their About/Links pages. Tests: command creates a user with
pages; username validation holds.

### Phase 2 — Posts CRUD (dashboard, private)
`posts` migration, `Post` model, `PostPolicy`. Dashboard lists drafts +
published, scoped to the owner. Create / edit / delete; save-as-draft; slug
auto-generated from title (editable while draft). The load-bearing test: a user
cannot see or edit another user's posts.

### Phase 3 — Publish lifecycle
Publish (`draft → published`, set `published_at`, **freeze slug**) and unpublish
(`published → draft`, keep slug + `published_at`). Tests pin that the slug is
frozen across publish/unpublish and timestamps behave.

### Phase 4 — Pages (About / Links)
Edit the two fixed Markdown pages in the dashboard, ownership-scoped.

### Phase 5 — Public blog (reader-facing)
Public routes `/@{username}`, `/{slug}`, `/about`, `/links`. Markdown rendered
**safely** (raw HTML stripped, unsafe links neutralized). Only published posts
listed/reachable; drafts and bad URLs → clean **404, never 403**, so a draft's
existence never leaks. Tests: drafts invisible; 404s correct; an XSS payload in
Markdown is neutralized.

### Phase 6 — Polish & harden
Reading layout/typography, empty states, full test pass, security self-review.

### Phase 7 — Deploy (LIVE 2026-07-07)
On a public HTTPS subdomain behind the shared Caddy proxy (systemd user service
+ `trustProxies` + Caddy `reverse_proxy` + UFW restriction). Full steps in
[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

Decisions locked 2026-07-07: go public (deploy when ready, no deadline);
subdomain `simpleblog.brianjgoodwin.dev`; port `8001`. **Production runs
SQLite** (read-heavy, near-zero write concurrency = SQLite's best case, WAL
mode). It was briefly MySQL during early scaffolding, then reverted to SQLite
for simplicity. No manual DNS step: a wildcard `*.brianjgoodwin.dev` already
resolves the subdomain.

### Phase 8 — Composer improvements (2026-07-10)
Live preview, writing ergonomics, autosave, publish-from-composer.

- **Live Markdown preview** — server-rendered by an authenticated endpoint
  (`POST /dashboard/posts/preview`) through the *same* `App\Support\Markdown`
  pipeline as the public pages, so the preview is byte-for-byte what publishes
  and XSS stripping behaves identically. Write/Preview tabs; render on switch.
- **Ergonomics** — auto-growing textarea, live word count, Ctrl/Cmd-S submits.
  Alpine.js only (already a dependency); no new packages.
- **Autosave — DRAFTS ONLY, edit form only.** A published post must never have a
  half-typed sentence pushed live by a timer, so published posts keep deliberate
  manual saves. New posts autosave only after the first manual "Save Draft"
  creates the record. Debounced via the update endpoint returning JSON.
- **Publish from composer** — a second submit button on both forms.

### Phase 9 — Accessibility fixes (deployed 2026-07-10)
From a WCAG 2.2 AA audit; seven steps, one commit each so history documents what
each fix was for. Highlights:

1. Fixed the unpublish button (a real functional bug: `x-secondary-button`
   defaults to `type="button"`, so "Move back to draft" never submitted).
2. Autosave live region: one persistent `<span role="status">` whose text
   changes (reliable announcement) instead of toggling separate spans.
3. Breeze nav + titles: visible focus rings, `aria-expanded`/`aria-haspopup`,
   `aria-label` on the hamburger, Escape closes the menu, per-page `<title>`.
4. Dropped a half-implemented `role="tablist"` for honest `aria-pressed`
   buttons (a two-state mode toggle, not a tab widget).
5. Owned the pagination views (published Breeze's) to fix focus-ring contrast
   and lang strings.
6. Heading hierarchy: author Markdown headings shift down one level (`#` → h2,
   capped at h6) in the single pipeline, so no body can emit an `<h1>` that
   competes with the page title.
7. Contrast + affordance sweep: darker hovers, persistent (non-hover) link
   underlines for touch users, `<time>` elements, `role="status"` flashes,
   dashboard lists as real `<ul>`s.

#### Phase 9 follow-up — second audit pass (2026-07-17)
A re-audit found the base holding up well; three refinements shipped:

1. **Modal dialog semantics** — `role="dialog"`, `aria-modal`, optional
   `aria-labelledby`; focus returns to the triggering element on close (it was
   falling to `<body>`).
2. **Skip link** — a visible-on-focus "Skip to content" link past the dashboard
   nav (WCAG 2.4.1); `<main id="main" tabindex="-1">` as the target.
3. **Form errors associated with fields** — `x-text-input` auto-wires
   `aria-invalid` + `aria-describedby` from the default error bag;
   `x-input-error` emits a stable `id="{field}-error"`. Named-bag inputs and raw
   textareas set these explicitly; radio-group errors attach to their
   `<fieldset>`.

Two items were deliberately deferred (both low-impact, recorded in the roadmap):
dropdown menu semantics (the panel is plain links) and a `prefers-reduced-motion`
fallback (AAA, brief transitions).

### Phase 10 — Blog appearance settings (deployed 2026-07-12, commit c9f6072)
Themes are CSS-only: each maps to a `[data-theme]` block of custom properties in
`app.css`; rendered post HTML is byte-identical across themes. The AA rule
caught a real failure during the build — `gray-500` muted text clears 4.5:1 only
on pure white, so tinted themes darken muted text to `#5d6673` via a
`--theme-muted` variable. Shipped with a serif/sans font toggle (system fonts
only — no webfonts on public pages). Adding a theme = an enum case + a CSS block
+ matching `Theme::swatch()` hexes + the four-ratio contrast check.

**Three more themes (2026-07-17): Honey / Ember / Iris** — subtle yellow,
orange, and purple tints (seven total). Accents AA-verified on their
backgrounds (Honey `#854d0e` 6.43:1, Ember `#b23c0e` 5.41:1, Iris `#6b21a8`
7.94:1). The "verify before shipping" rule is now automated:
`tests/Unit/ThemeContrastTest.php` parses every `[data-theme]` block from the
CSS, does the ratio math, and asserts each `Theme` case's `swatch()` stays in
sync with its CSS block — so a failing or drifted theme fails CI.

### Phase 11 — Invite codes (deployed 2026-07-12, commit a0edc69)
Reintroduced self-registration, gated by server-generated invite codes. The
registration route had been *removed* entirely (author:create was the only
account path), so this re-opened that endpoint with the code as the lock.

- **One `invites` table, deliberately dumb:** `code` (unique), `note`
  (nullable), `used_at` (nullable), `used_by_id` (nullable FK). Valid iff
  `used_at IS NULL` — that single fact is the whole state machine; no status
  enum. Codes stored **plaintext** by decision (hashed codes can't be re-listed,
  which would force code-tracking into a side text file — a worse posture; codes
  aren't passwords).
- **Generation:** `invite:generate {count} {--note=}` prints codes;
  `invite:list` shows used/unused. Unambiguous alphabet (no 0/O/1/l), grouped
  `Xxxx-Xxxx-Xxxx`, ~70 bits; validation normalizes before lookup.
- **Atomic consumption:** a guarded `UPDATE ... WHERE used_at IS NULL` with its
  row count checked — `lockForUpdate()` is a no-op on SQLite, so the guarded
  update is the real race fence. Kills the two-signups-one-code race.
- **Username rules unforked:** extracted to `User::usernameRules()` and page
  seeding to `User::seedDefaultPages()` (`DEFAULT_PAGES` shared with the
  PageController allow-list) before a second copy could drift. A test pins that a
  registered account is byte-identical to an `author:create` one.
- **Open question resolved (Brian):** codes do **not** bind to an email — any
  code, any email, first-come-first-served; `note` is the memory aid.
- Security: `throttle` on the register routes (~5/min/IP) makes code-guessing
  infeasible; honest error messages are fine at 70 bits; no public link to
  `/register` (the URL travels with the invite).

### Phase 12 — Atom feed + follow features (2026-07-17)
Built on the `body_html` cache (below), in three commits: the feed, Open Graph
meta, the sitemap — plus the blog description that the `<subtitle>` needed.

- **Feed** at `/@{username}/feed` — published only, newest first, capped at 20;
  hand-rolled Atom (`feed/atom.blade.php`, ~40 lines); entry `<id>`s are
  permalinks (permanent, because slugs freeze at first publish, so readers never
  re-show an old post as new); `<content type="html">` carries the cached
  `body_html`, entity-encoded; `abortIfSuspended` first. **No firehose feed** —
  an all-authors feed would undo the landing page's "reveal nothing about who
  has blogs here" posture.
- **Conditional GET** — `Last-Modified = max(updated_at)` of published posts via
  one aggregate query; `If-Modified-Since` answered with a bare 304 before any
  body is loaded. Turns unattended feed polling from the biggest recurring cost
  into a rounding error.
- **Discovery** — autodiscovery `<link>` + a visible Feed nav link.
  **Microformats** (h-feed / h-entry / p-name / u-url / dt-published / e-content,
  h-card on the author) on markup already rendered — machine-readable to the
  IndieWeb ecosystem at near-zero cost.
- **Open Graph + description** — og:* + twitter:card on all public pages;
  article tags + a `Post::excerpt()` description on post pages.
- **sitemap.xml** at `/@{username}/sitemap.xml` — home, About, Links, published
  posts; same `published()` scope and suspended guard.
- **Blog description** — a nullable `description` column (max 200, not fillable,
  blank stored as null), edited on the Appearance page. Shown under the blog
  name, carried as the Atom `<subtitle>`, and used as the home meta/OG
  description. Plain text, escaped on output (verified with a `<`/`&`/quote
  value).

Open questions resolved: `<updated>` uses honest `updated_at` (edits may
re-surface a post, accepted as truthful); OG rode along here. **Explicitly out**
(look cheap, aren't): email subscriptions, ActivityPub, WebSub — see PLAN.md for
the reasoning, which still stands.

### Phase 13 — Author export (deployed 2026-07-11, commit 5d9985a)
The feature that *is* the project's philosophy: if the pitch is "escape walled
gardens," the acid test is whether authors can escape *us*.

- **Format — a zip of plain files, no proprietary manifest:** one `.md` per post
  (drafts included), named by slug; minimal YAML front-matter (`title`, `slug`,
  `status`, `published_at`, `created_at`, `updated_at`); `about.md` + `links.md`
  alongside. No HTML in the zip — the Markdown is the canonical form.
- **Mechanics:** `GET /dashboard/export` streams a `ZipArchive`, auth'd, scoped
  to the logged-in user. Cheap precisely because of a founding decision — the
  words were never converted to anything else; the Markdown sits in a DB column.
- Title front-matter is JSON-encoded (a JSON string is a valid YAML scalar — no
  hand-rolled escaping). The cross-author scoping test was verified to fail when
  scoping is deliberately broken.

### Phase 14 — Operator hardening (deployed 2026-07-11, commit fec58c0)
The moment invites land, this stops being a blog and becomes hosting — other
people's words, on Brian's server. Sequenced before Phase 11 for that reason.

1. **Suspend/unsuspend tooling:** nullable `suspended_at` on users (NOT
   fillable). `author:suspend` / `author:unsuspend`. A suspended blog is **404
   everywhere public** (home, posts, pages, feed) — publicly indistinguishable
   from one that never existed, same posture as drafts. `EnsureAuthorNotSuspended`
   ends an existing session on the next request.
2. **Content-Security-Policy on public pages:** `PublicContentSecurityPolicy`
   middleware, roughly `default-src 'none'; style-src 'self'; img-src 'self';
   base-uri 'none'; form-action 'self'; frame-ancestors 'none'`. The Markdown
   pipeline already strips HTML — the CSP is the backstop for the day a parser
   CVE or a future feature pokes a hole. Cheap *because* the public surface is
   tiny (one same-origin stylesheet, no JS, no fonts). Skipped when Vite is hot.
   Known accepted gap: a binding-layer 404 carries no header (no content to
   protect there).
3. **Acceptable-use page** (`/acceptable-use`): one paragraph of rules, one of
   promises. Its real function is to make suspending an account a policy action
   instead of a personal argument.

### Smaller features (2026-07-17)
Three self-contained additions, each with the same `published()` scope +
`abortIfSuspended` posture, so none can leak a draft or a suspended blog:

- **Archive** (`/@{username}/archive`) — every published title, newest first,
  grouped by year (one query + `groupBy`, one template). Reserved word before
  the `{slug}` route; linked from the blog nav (now `flex-wrap` for five items).
- **Privacy** (`/privacy`) — a host-level static page, sibling of
  `/acceptable-use`: what we store, what readers get (public pages load nothing
  but page + stylesheet — the CSP proves it), and export/delete as the exit.
  Linked from the site footer, reachable everywhere.
- **Search** (`/@{username}/search?q=`) — a `Post::scopeSearch($term)` (title OR
  body `LIKE`, wildcards escaped with `=`), composed with `published()`; a
  reused search-box partial on the home page; paginated results (10/page, title
  + date + excerpt), blank query shows a prompt.

  **Decision — `LIKE`, not full-text (with a correction):** search was built with
  a plain `LIKE` scope on the belief that production ran MySQL, which would have
  ruled out SQLite's FTS5. That premise was wrong — **production is SQLite**, so
  FTS5 *is* available and is the natural upgrade path if search ever needs
  ranking or speed. `LIKE` remains a fine choice at single-blog scale, though: no
  index, no migration, no virtual table, and it's swappable behind the scope
  without touching callers.

### Public-page performance pass (2026-07-17)
Indexes were already right (users.username unique; pages `(user_id, slug)`
unique; posts `(user_id, status, published_at)` composite — every public query
covered). Two changes shipped:

- **Narrow selects** on the river, feed, and search: since `body_html` landed,
  the Markdown source `body` was dead weight on every public read (each row
  carried both columns — roughly double the payload for a page). Archive and
  sitemap already selected narrow.
- **Conditional GET on the post permalink**, same pattern as the feed:
  `Last-Modified = max(post.updated_at, author.updated_at)` → 304 for repeat
  readers (the author term catches name/description/theme changes that alter the
  page shell). Safe on the permalink *only* because unpublish/delete 404s the
  next request before any 304. Deliberately **not** on the home river:
  unpublishing wouldn't advance `max(updated_at)` of published posts, so a 304
  could keep serving a river that still shows the unpublished post. Flagged-not-
  done alongside it: splitting public routes off the session stack (real win, but
  touches the auth/CSRF posture) and `Cache-Control: public, max-age` (would
  serve stale content after edits; revalidation fits better).

### River page size (2026-07-17)
The blog home shows **5 posts per page** (was 10). The river renders full
bodies, so this halves page weight and scroll length; older posts are one
pagination click — or the Archive — away. Search stays at 10 (its rows are
title + excerpt, not full bodies).

---

## Decision log

### Markdown render caching: Option A — `body_html` column (decided 2026-07-11, built 2026-07-17)
Public pages rendered Markdown → HTML on every request; the river renders
several bodies per hit and the feed re-renders every body on every non-304 poll,
forever, against a single process. Trivial at one author, but the feed changed
the math.

**Chosen — Option A:** a nullable `body_html` on posts, filled from
`App\Support\Markdown` in the model's single render path (a `saving` hook that
re-renders whenever `body` is dirty — so the controller, the composer autosave,
the factory, and tinker all populate it and it can't drift). `body` stays
canonical (export and search want the source). A `bodyHtml` accessor returns an
`HtmlString`, so views echo `{{ $post->body_html }}` and the XSS safety lives in
the render path, not the view. `php artisan posts:rerender` rebuilds every row
after a pipeline change (heading-shift tweak, CommonMark upgrade), using
`withoutTimestamps` so a mechanical re-render never bumps `updated_at` (which the
feed's `<updated>` reads). That is the whole invalidation story — no TTLs, no
cache keys.

**Rejected — Option B (`Cache::remember`):** adds invalidation semantics where
Option A has none; cold cache after every deploy re-renders everything (exactly
when feed polling hits); a second copy of truth rather than a column beside its
source. **Rejected — Option C (defer, render per request):** legitimate until
the feed, which is the first client that polls unattended. Option A wins because
the app's whole character is "state lives in the DB, plainly" — a cached column
with an explicit re-render command matches that; a cache layer with TTLs doesn't.

### Other resolved decisions
- **Markdown sanitization:** CommonMark (GitHub-flavored) with `html_input =>
  'strip'` and `allow_unsafe_links => false`. Authors can't embed raw HTML —
  fine, and it's the app's main XSS defense. (Was an open pre-build question.)
- **Database:** SQLite in both local dev and production (zero-config, the repo
  default; briefly MySQL during scaffolding, reverted for simplicity).
- **Test framework:** Pest.
- **App name:** kept `simple-blog`.
- **Atom `<updated>`:** honest `updated_at` over quiet `published_at`.
- **Invite codes:** do not bind to an email; stored plaintext; revocation =
  delete the unused row.
