# Simple Blog — Project Plan

**Status:** Planning
**Rigor:** "For real" — bottom-up, do it right, understand every layer.
**Working name:** `simple-blog` (rename freely before we `laravel new`)

A minimal, multi-tenant, Markdown-only blogging application. One account = one blog.
Invited authors only; the public reads. Built on Laravel + Breeze.

---

## 1. Concept

A tiny blog host. I invite people; each gets one account and one blog namespaced
under their username. Authors write Markdown (no images), manage drafts and published
posts, and edit two pages (About, Links). Readers browse a given author's published
posts at clean public URLs. Deliberately minimal — "a simple way to put words online,"
not a blogging platform.

---

## 2. Locked decisions

| Area | Decision |
|---|---|
| Rigor | "For real" — bottom-up |
| Content | Markdown only, no images |
| Multi-tenancy | One account = one blog; invite-only, no open registration |
| Public URLs | `/@{username}`, `/@{username}/{slug}`, `/@{username}/about`, `/@{username}/links` |
| Usernames | Immutable after creation |
| Auth | Laravel Breeze (Blade + Tailwind) |
| Authoring | Separate `/dashboard`, auth-gated, scoped to logged-in user |
| Pages | About/Links are editable Markdown, stored in DB |
| Post lifecycle | `status` enum (`draft`/`published`, room for `unlisted`) + `published_at` |
| Slugs | Auto from title, editable while draft, frozen at first publish |
| Unpublish | Allowed; published → draft reuses the same frozen slug |
| Visibility (v1) | Published = listed & public; drafts private. `unlisted` modeled-for, not built |
| Account creation | `php artisan` command (no admin UI in v1) |
| Public pages | Work with JavaScript disabled — curl-able, archivable, readable in 20 years. True today by accident; locked so it stays true on purpose |

---

## 3. URL map

| URL | Access | Purpose |
|---|---|---|
| `/@{username}` | public | Author's blog home — published posts, newest first |
| `/@{username}/{slug}` | public | A single published post |
| `/@{username}/about` | public | Author's About page |
| `/@{username}/links` | public | Author's Links page |
| `/login`, `/logout` | public | Breeze auth |
| `/dashboard` | author only | Drafts, published, new post, pages — scoped to you |
| `/dashboard/posts/create` | author only | New post editor |
| `/dashboard/posts/{post}/edit` | author only | Edit a post you own |
| `/dashboard/pages/{page}/edit` | author only | Edit About or Links |

The `@` prefix on public blog routes deliberately prevents any collision between
usernames and app routes (`/login`, `/dashboard`). No reserved-word list needed.

---

## 4. Data model

### `users` (extends the Breeze default)
- `id`
- `name` — display name (byline, blog title-ish)
- `username` — unique, immutable, slug-safe (`^[a-z0-9_]+$`), used in URLs
- `email` — unique (login + password reset)
- `password`
- timestamps
- *(Breeze's default email-verification columns kept but unused in v1)*

### `posts`
- `id`
- `user_id` — FK → users, indexed. Every post belongs to exactly one author.
- `title`
- `slug` — unique **per user** (composite unique on `user_id + slug`), frozen at first publish
- `body` — Markdown source
- `status` — enum: `draft` | `published` (leave room for `unlisted`)
- `published_at` — nullable timestamp; set on first publish, retained on unpublish
- timestamps
- Indexes: `user_id`, composite `(user_id, slug)` unique, and `(user_id, status, published_at)` for the public listing query

### `pages`
- `id`
- `user_id` — FK → users
- `slug` — `about` | `links` (fixed set in v1)
- `body` — Markdown source
- timestamps
- Unique on `(user_id, slug)`
- Each user gets an About + Links row seeded on account creation

**Relationships:** `User hasMany Post`, `User hasMany Page`, `Post belongsTo User`, `Page belongsTo User`.

---

## 5. Security & correctness (the "for real" checklist)

This is the part that separates "kind of working" from done. Non-negotiables:

- **Ownership authorization.** Every dashboard action must verify the post/page belongs
  to the logged-in user. Use a Laravel **Policy** (`PostPolicy`, `PagePolicy`) — not
  ad-hoc `if` checks. This is the single most important thing to get right in a
  multi-tenant app; a missing check lets user A edit user B's posts.
- **Route-model binding scoped to the user.** Prefer `->scopeBindings()` / child route
  binding so `/dashboard/posts/{post}` can only resolve a post the current user owns.
- **Markdown rendering is the #1 XSS surface.** Rendered Markdown becomes HTML on public
  pages. We must sanitize output (CommonMark with the raw-HTML input disabled, or an
  HTML sanitizer pass). Decision needed — see §8.
- **Mass-assignment discipline.** Guard `user_id`, `slug`, `status` — never let them be
  set straight from request input. Set them server-side.
- **Validation** on every write: username format/uniqueness, title/body presence, slug
  format.
- **CSRF** is automatic with Breeze Blade forms — keep forms server-rendered.
- **404, not 403, for private content** on public routes — don't leak existence of drafts.

I'll flag anything else as we build. (Per how we work: I call out security issues as we go.)

---

## 6. Tech stack

- **Laravel** (latest) + **Breeze** (Blade + Tailwind) for auth scaffolding
- **PHP 8.4** locally (per this server's setup)
- **Database:** SQLite for local dev (zero-config, file-based, perfect for "for real but
  solo" — and trivial to point at MySQL later). Decision to confirm — see §8.
- **Markdown:** `league/commonmark` (Laravel's `Str::markdown()` uses it under the hood)
- **Testing:** Pest or PHPUnit — feature tests for the auth/ownership/visibility rules

---

## 7. Build phases (implementation order)

Each phase is a coherent, testable chunk sized for a focused session. We build,
you steer, we verify before moving on.

### Phase 0 — Scaffold
- `laravel new`, install Breeze (Blade), configure SQLite, first migration + run
- Commit a clean baseline. Confirm login works.

### Phase 1 — Users & accounts
- Add `username` (immutable) to users migration + model
- `php artisan make:command CreateAuthor` → creates user + seeds their About/Links pages
- Feature test: command creates a user with pages; username validation holds

### Phase 2 — Posts CRUD (dashboard, private)
- `posts` migration, `Post` model, `PostPolicy`
- Dashboard: list drafts + published (scoped to you)
- Create / edit / delete post; save-as-draft
- Slug auto-generation from title (draft-editable)
- Feature tests: a user cannot see or edit another user's posts (the big one)

### Phase 3 — Publish lifecycle
- Publish action: `draft → published`, set `published_at`, **freeze slug**
- Unpublish action: `published → draft`, keep slug + `published_at`
- Tests: slug frozen across publish/unpublish; timestamps behave

### Phase 4 — Pages (About / Links)
- Edit About/Links Markdown in dashboard
- Tests: ownership scoping

### Phase 5 — Public blog (reader-facing)
- Routes: `/@{username}`, `/@{username}/{slug}`, `/@{username}/about`, `/links`
- Render Markdown **safely** (see §5)
- Only published posts listed/reachable; drafts + bad URLs → clean 404
- Tests: drafts invisible publicly; 404s correct; XSS payload in Markdown is neutralized

### Phase 6 — Polish & harden
- Layout/typography for reading, empty states, minimal styling
- Full test pass, security self-review, README with setup + `create-author` usage
- Decide git remote (GitHub now; Gitea later per your infra plans)

### Phase 7 — Deploy (DONE — LIVE 2026-07-07)
- Put Simple Blog on a public HTTPS subdomain behind the shared Caddy proxy,
  reusing the Puzzlebox pattern (systemd user service + `trustProxies` + Caddy
  reverse_proxy + UFW restriction). Full step-by-step in
  [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).
- **Decisions locked (2026-07-07):** go public (the goal, no deadline — deploy when
  ready); subdomain `simpleblog.brianjgoodwin.dev`; port `8001`; keep SQLite in
  production (read-heavy, near-zero write-concurrency = SQLite's best case; confirm
  WAL mode on deploy). Rationale in docs/DEPLOYMENT.md.
- No manual DNS step: a wildcard `*.brianjgoodwin.dev` already resolves the subdomain
  (Puzzlebox has no per-subdomain record either). Going live = adding the Caddyfile
  block + starting the service; the "flip it public" call is Brian's.

### Phase 8 — Composer improvements (2026-07-10)
Requested: live preview, writing ergonomics, autosave, publish-from-composer.

- **Live Markdown preview** — server-rendered by a small authenticated endpoint
  (`POST /dashboard/posts/preview`) through the SAME `App\Support\Markdown`
  pipeline as the public pages, so the preview is byte-for-byte what publishes
  and XSS stripping behaves identically. Write/Preview tabs; render on switch.
- **Ergonomics** — auto-growing textarea, live word count, Ctrl/Cmd-S submits
  the form. Alpine.js only (already a dependency), no new packages.
- **Autosave — DRAFTS ONLY, on the edit form only.** Design decision: a
  published post must never have a half-typed sentence pushed live by a timer;
  published posts keep deliberate manual saves. New posts autosave after the
  first manual "Save Draft" creates the record. Debounced (saves a moment
  after typing pauses) via the existing update endpoint returning JSON.
- **Publish from composer** — a second submit button on both forms
  (`action=publish`) that SAVES the current content, then publishes. This also
  fixes a pre-existing trap: the old separate Publish form published the last
  saved version, silently ignoring unsaved edits in the textarea above it.
  The standalone publish card on the edit page is removed; the unpublish card
  stays (returning to draft is a distinct, deliberate act).

### Phase 9 — Accessibility fixes (DONE — deployed 2026-07-10)
From the WCAG 2.2 AA audit (full findings in the audit report / session log).
Seven steps, ordered by impact; one commit per step so each is revertable and
the history documents what each fix was for. No new packages.

1. **Fix the unpublish button (functional bug).** `x-secondary-button`
   defaults `type="button"`, so "Move back to draft" never submits its form.
   Pass `type="submit"` in `posts/edit.blade.php`. Add a browser-shaped
   regression guard: a feature test asserting the rendered edit page contains
   a submit-type button inside the unpublish form (route tests already cover
   the backend, which is why this slipped through).
2. **Autosave live region.** In `posts/_fields.blade.php`, replace the three
   `x-show` status spans with ONE persistent `<span role="status">` whose text
   is bound with `x-text` (a persistent container whose contents change
   announces reliably; toggling elements often doesn't). Word count stays
   outside the region (per-keystroke announcements would be noise). Add
   `x-cloak` so nothing flashes pre-Alpine.
3. **Breeze nav + titles.** In `navigation.blade.php` / `dropdown.blade.php`:
   visible focus ring on the account-menu trigger (it has `focus:outline-none`
   with no replacement), `:aria-expanded` on trigger + hamburger,
   `aria-label` on the hamburger, `@keydown.escape.window` to close the
   dropdown, real focus style on dropdown items (gray-100-on-white is
   invisible). Per-page `<title>` via a `$title` prop in `app.blade.php` /
   `guest.blade.php` (public layout already does this correctly).
4. **Drop the half-tablist.** Remove `role="tablist"` from the Write/Preview
   wrapper; add `:aria-pressed` to the two buttons. Design decision: it is a
   two-state mode toggle, not a tab widget — completing the full ARIA tabs
   pattern (5 attributes + arrow-key JS) buys nothing here.
5. **Own the pagination views.** `artisan vendor:publish
   --tag=laravel-pagination` + publish `lang/en/pagination.php`. Fix in the
   published copies: focus ring ≥3:1 (stock is gray-300 = 1.47:1), plain
   "Previous"/"Next" lang strings (stock double-escapes `&laquo;` into the
   aria-label), hover arrow contrast, strip `dark:` classes (layout has no
   dark mode, so OS-dark users currently get dark buttons on a white page).
6. **Heading hierarchy.** (a) Shift author Markdown headings +1 level in
   `App\Support\Markdown` (`#` → h2, capped at h6) — single pipeline, so
   preview and public output stay identical; river shows h2 titles with h2
   author headings as siblings, post page shows h1 title over h2+, both sane.
   Unit tests for the shift + cap. (b) Blog name becomes `<h1>` on the public
   home; `public/page.blade.php` gets a heading; the authenticated header
   slot becomes h1 (pages currently start at h2). Note: (a) changes rendered
   HTML of existing posts — re-render caching (open decision) lands after
   this, or must invalidate.
7. **Contrast + affordance sweep.** Darken hovers instead of lightening
   (Publish `hover:bg-green-600` → green-800; danger `hover:bg-red-500` →
   red-700); `text-green-600` status → green-800; hamburger icon gray-400 →
   gray-500; persistent underlines on public template links (touch users
   never see `hover:underline`); footer ♥ wrapped `aria-hidden` with sr-only
   "love"; `<time>` elements on dates; `<main>` on welcome/404; `role="status"`
   on flash messages; dashboard lists become `<ul>`.

Verify: full Pest suite + manual keyboard pass (tab through nav, dropdown,
composer, pagination) + live check after deploy (`npm run build`,
`view:clear`; no new routes so no `route:cache` concern).

### Phase 10 — Blog appearance settings (SKETCH — designed 2026-07-10, not scheduled)
Light per-author customization of the public blog. Deliberately small: a
serif/sans toggle plus a handful of bundled, pre-verified themes. Roughly a
one-session build; most of the work is choosing colors and verifying contrast.

**Scope (v1):**
- **Font toggle:** serif vs sans. System font stacks only (no webfonts on the
  public pages — keeps the zero-external-requests posture). `prose` inherits
  the family, so one conditional class on the public layout's `<body>` does it.
- **Named themes, 3–4, bundled not mixable:** each theme = background tint +
  accent color (links/underlines, masthead, header border). Body text stays
  gray-900-on-light in every theme. Bundling matters: independent axes
  multiply the contrast-verification work (4 accents × 3 backgrounds = 12
  combos); bundled themes add (4 themes = 4 verifications, done once, by us).

**Architecture decisions (locked at design time):**
1. **Themes are CSS-only, never HTML-level.** A theme changes only a
   `data-theme` attribute + font class on the public `<body>`, and CSS custom
   properties in app.css say what each theme means. Rendered post HTML is
   byte-identical across themes — so the open body_html caching decision
   (Option A) is unaffected by this feature. If a theme ever needs different
   markup, that's a scope change, not a tweak.
2. **Tailwind is compile-time**, so no interpolated classes
   (`bg-{{ $color }}-100` is invisible to the build). Every theme's CSS is
   written out by hand in app.css using CSS variables; templates reference
   `var(--accent)` etc. via a few custom utility lines.
3. **Enum-backed columns, like PostStatus:** `theme` and `font` string columns
   on `users`, each validated against a PHP enum. No settings table, no JSON
   column — flexibility we've decided not to need. Adding a theme later =
   enum case + CSS block + one contrast verification.
4. **Every theme ships AA-verified.** The limited set is the a11y guarantee:
   we check each theme's pairs once (incl. the decoration-gray-300 underline
   equivalent per background) and authors can't produce a failing combination.

**UI:** a small dashboard settings page (radio buttons, Save), "view your
blog" link as the preview. No live preview in v1.

**Explicitly out of scope (v1):**
- Dark mode — looks like "one more theme," is actually a project: full
  contrast re-audit, prose-invert, dark values for nav/pagination/footer.
- Free-form colors or custom CSS — breaks the verified-once contrast
  guarantee; author CSS on public pages is also an injection/exfiltration
  surface (e.g. CSS url() beacons).
- Layout options (column width, etc.) — against the opinionated character.
- Author-supplied webfonts — external requests, licensing, and privacy.

### Phase 11 — Invite codes (SKETCH — designed 2026-07-10, not scheduled)
Reintroduce self-registration, gated by server-generated invite codes that
Brian hand-distributes to testers. Framing matters: registration routes were
REMOVED entirely (author:create is the only account path today), so this
feature re-opens that endpoint — the code is the lock on it. Roughly a
one-session build.

**Data model — one `invites` table, deliberately dumb:**
- `code` (string, unique), `note` (nullable — "for Dave" / batch label),
  `used_at` (nullable timestamp), `used_by_id` (nullable FK users), timestamps.
- Valid iff `used_at` is null — that single fact is the whole state machine;
  no status enum (there is no third state). `used_by_id` = permanent audit
  trail of which tester came from which code.
- No `expires_at` in v1: revocation = delete the unused row.
- Codes stored PLAINTEXT (decision, not oversight): hashed codes can't be
  re-listed, forcing code tracking into a text file — a worse posture. Codes
  are not passwords; an attacker who can read `invites` can read `users`.

**Generation — artisan, matching the author:create pattern (no admin UI):**
- `invite:generate {count} {--note=}` prints codes; `invite:list` shows
  used/unused.
- Format: random from an unambiguous alphabet (no 0/O/1/l), grouped for
  humans (e.g. `Kk7m-Xw4r-Tn2p`), ~70 bits. Validation normalizes (strip
  dashes/whitespace) before lookup.

**Registration flow:**
- Distribute as links: `/register?code=...` pre-fills an editable code field.
  Form = code + name + username + email + password. Creates a plain author,
  byte-identical to author:create output (no roles, no flags).
- **Username rules must not fork:** extract the canonical rules from
  CreateAuthor (`lowercase`, `regex:/^[a-z0-9_]+$/`, `max:30`, `unique`) into
  one shared place (e.g. `User::usernameRules()`) BEFORE writing the second
  copy, or they will drift.
- **Atomic consumption:** inside `DB::transaction`, re-fetch the invite with
  `lockForUpdate()`, confirm `used_at` still null, create user, stamp
  `used_at`/`used_by_id`. Same shape as author:create's transaction, plus the
  lock. Kills the two-signups-one-code race outright.

**Security posture (this re-opens an unauthenticated endpoint):**
- `throttle` middleware on the register routes (~5/min/IP) — this is what
  actually makes code-guessing infeasible, and blunts username/email
  enumeration probing.
- Error messages CAN be honest ("code already used" vs "invalid") — at 70
  bits, guessing can't reach a valid-but-used code, and honesty helps a
  confused tester. Unlike password reset, no paranoid-generic errors needed.
- No public link to /register anywhere — the URL travels with the invite.
  Landing page's "invite-only" copy stays truthful.
- NOT needed: email verification (testers are known), CAPTCHA (the code is
  the gate).

**Open question before building:** should a code bind to an email? Assumed
NO — any code, any email, first-come-first-served; `note` is the memory aid.
Pre-binding adds friction and schema for a problem hand-distribution doesn't
have. Brian's call — he knows the testers.

**Tests that matter:** used code rejected; code consumed exactly once under
concurrent submits; registered account matches an artisan-created one;
throttling kicks in.

### Phase 12 — Atom feed + follow features (SKETCH — designed 2026-07-10, not scheduled)
Promotes RSS out of the deferred list. One feed per blog; a "follow" story
without accounts, email, or federation. One session as scoped, with the
Markdown caching decision as flagged prerequisite-or-companion.

**The feed:**
- `/@{username}/feed` — published posts only, newest first, capped ~20.
- **No site-wide firehose feed** (decision): the landing page reveals nothing
  about who has blogs here; an all-authors feed would quietly undo that. Same
  reasoning as the drafts-404 posture. Don't add it innocently later.
- **Hand-rolled Atom, one format done well.** All readers handle RSS 2.0 and
  Atom alike; Atom is the better-specified format (unambiguous dates,
  required unique IDs, defined model for embedded HTML). No package — it's
  ~40 lines of Blade XML template and the format is worth learning. JSON
  Feed later only if the mood strikes (second trivial template, same data).
- **Full content** through App\Support\Markdown (matches the river's
  character; XSS posture and heading shift carry into readers automatically).
  Blade's {{ }} does the XML-escaping into <content type="html">.
- **Entry IDs = permalink URLs**, permanent because slugs freeze at first
  publish — an existing architectural property pays off; readers never
  re-show old posts as new.
- **Discovery:** <link rel="alternate" type="application/atom+xml"> in the
  public layout head + a visible "Feed" text link in the blog nav/footer.

**Conditional GET — the do-it-right piece:** readers poll every 15–60 min
per subscriber forever; ~10 followers ≈ 500 req/day against single-process
artisan serve rendering Markdown each hit. Set Last-Modified (and/or ETag)
from max(updated_at) of published posts; answer If-Modified-Since with 304
and an empty body (Symfony response layer has this built in). Turns feed
polling from the biggest recurring cost into a rounding error. Feeds
re-render every body on every non-304 hit — strongest argument yet to
settle the body_html caching decision (Option A) before or with this phase.

**Safety test that matters:** the feed uses the exact same published() scope
as the public pages, with a test asserting a draft NEVER appears — the feed
is a new place for the drafts-never-leak guarantee to fail.

**Riding along:**
- **Open Graph + description meta tags** on public pages — post links unfurl
  with title/author/date when pasted in chat/social. A handful of meta lines
  in the public layout; highest visible-impact-per-line item in the phase.
- **sitemap.xml per blog** (optional) — same Blade-XML-over-published-posts
  shape as the feed; more discovery than follow.
- **Microformats (h-entry / h-card)** — a handful of classes (`h-entry`,
  `p-name`, `e-content`, `dt-published`, `h-card` on the author name) on HTML
  we already render. Makes every blog machine-readable to the IndieWeb
  ecosystem (readers, webmention senders, archivers) without building any of
  that ecosystem ourselves. Near-zero cost; belongs in the same commit as
  feed discovery links.

**Explicitly out (look cheap, aren't):**
- Email subscriptions — deliverability, unsubscribe compliance, address
  storage (privacy weight), bounces. RSS serves the same need here.
- ActivityPub / follow-from-Mastodon — webfinger + HTTP signatures +
  inbox/outbox + follower storage; a multi-week project with an operational
  tail. RSS gets 80%; bridges cover the rest.
- WebSub — needs hub coordination; buys nothing at this scale that 304s don't.

**Open questions before building:**
1. Atom `updated`: honest `updated_at` (edits may re-surface the post in
   some readers — arguably a feature) vs quiet `published_at` (corrections
   never re-surface). Recommendation: honest `updated_at`. Brian's call.
2. Do the OG meta tags ride along here or become their own line item?

### Phase 13 — Author export (SKETCH — designed 2026-07-11, not scheduled)
The feature that IS the project's philosophy: if the pitch is "escape walled
gardens," the acid test is whether authors can escape US. Every author can
download everything they've written, in the format they wrote it, at any time.
Cheap precisely because of a founding decision — we never converted their
words to anything else; the Markdown is sitting in a SQLite column.

**Format — a zip of plain files, no proprietary manifest:**
- One `.md` file per post (drafts included — they're the author's words too),
  named by slug (drafts: slug-so-far or a title-derived fallback).
- Minimal YAML front-matter per file: `title`, `slug`, `status`,
  `published_at`, `created_at`, `updated_at`. Nothing app-specific — the
  files should drop into Jekyll/Hugo/Kirby/anything with light massaging.
- `about.md` and `links.md` alongside.
- No HTML in the zip: the Markdown is the canonical form; rendered HTML is
  our presentation, not their content.

**Mechanics:**
- One button on the dashboard (or profile page): `GET /dashboard/export` →
  streamed `ZipArchive` response, auth'd, scoped to the logged-in user.
  No queue, no temp-file lifecycle if we stream; a blog of hundreds of
  Markdown posts zips in well under a second.
- Serves double duty as the author's personal backup — the systemd backup
  covers Brian against disk loss; export covers authors against Brian
  losing interest in 2029.

**Import (the natural sequel, separate line item):** accept the same zip
back. Gives migration between instances for free and forces us to keep the
export format honest. Not v1 of this phase — export alone ships value.

**Tests that matter:** export contains drafts + pages; another author's
posts NEVER appear (same scoping guarantee as the dashboard); front-matter
round-trips a post with quotes/colons/unicode in the title.

### Phase 14 — Operator hardening (SKETCH — designed 2026-07-11; sequence BEFORE Phase 11 ships)
The moment invites land, this stops being a blog and becomes hosting: other
people's words, under Brian's name, on Brian's server. Phase 11 must not go
live before this exists. Numbered 14 by creation order, sequenced before 11.

**1. Suspend/unsuspend tooling (artisan, matching author:create — no UI):**
- `author:suspend {username}` / `author:unsuspend {username}` — a nullable
  `suspended_at` timestamp on users (same one-fact pattern as invites'
  `used_at`; no status enum).
- Suspended blog = 404 everywhere public (home, posts, pages, feed) — the
  existing 404-not-403 posture extended: a suspended blog is
  indistinguishable from one that never existed. Suspended author can't log
  in (or is logged out via session invalidation) — decide at build time.
- This is the difference between handling a problem at 11pm with a command
  and hand-editing production SQLite while stressed.

**2. Content-Security-Policy header on public pages:**
- The Markdown pipeline strips HTML and tests pin that — CSP is the backstop
  for the day a CommonMark CVE or a future feature pokes a hole in it.
  Defense in depth is cheap here BECAUSE the pages are so simple; a strict
  CSP that would be agony on a JS-heavy site is nearly free on ours.
- Middleware on the public routes; roughly `default-src 'none'; style-src
  'self'; img-src 'self'; base-uri 'none'; form-action 'self'` — exact
  directives verified at build time against what the pages actually load
  (Vite asset origin, any inline styles Breeze/Alpine need on authed pages —
  which is why this starts public-routes-only).
- Test: response header present on `/@user` and absent nothing breaks —
  plus a manual pass with the browser console open (CSP violations are
  loud there).

**3. Acceptable-use paragraph (one paragraph, not legal theater):**
- Written and linked from the register page before the first invite goes
  out. Its real function: makes suspending someone a policy action instead
  of a personal fight with a friend. Also states the no-analytics posture
  out loud (see "Deliberately never" below).

### Smaller sketches (designed 2026-07-11, not scheduled)
Each is self-contained and roughly a session or less; none blocks anything.

- **Archive page** — `/@{username}/archive`: every published title,
  chronological, grouped by year. The river shows recent writing; the
  archive makes a blog feel like a body of work. One query, one template.
  Link from blog nav. Same drafts-never-appear test as home.
- **Post scheduling** — `status`+`published_at` already model it: publish
  with a future timestamp, public scopes become `published_at <= now()`,
  slug freezes at the moment of *scheduling* (it's leaving the author's
  hands). CATCH: "goes live" needs no process at all with the scope
  approach (time passes, query starts matching) — but autosave-vs-published
  rules and the composer UI need a third mental state, and the feed's
  Last-Modified math must use `max(published_at where <= now())`. Cheap
  mechanically, subtle at the edges — sketch says: don't rush it.
- **Per-blog search** — SQLite ships FTS5, so full-text search costs no new
  infrastructure — one of the rare stacks where this is true. External
  content table synced from posts (or contentless FTS over body), search
  box on the blog home, published-only (the guarantee again). Middling
  priority; high fun-per-line. Interacts with the body_html decision only
  in that FTS wants the SOURCE Markdown, another vote for keeping `body`
  canonical.
- **Custom domains per author** — the long-horizon ethos feature: nothing
  says "you own this" like `theirname.com` on their blog. Real work — Caddy
  on-demand TLS, a verified `domain` column, host-based routing coexisting
  with `trustHosts`, per-domain canonical URLs in feeds/OG tags. Filed as
  someday; this is where the project's philosophy ultimately points.

### Deliberately never (the restraint is a feature)
Stated here so future-us doesn't add one innocently. These aren't hard to
build — they're the mechanism by which walled gardens made writing
performative, and their absence is this project's differentiator:

- View counters, likes, reactions, follower counts
- Trending / recommended / algorithmic anything
- Analytics on authors or readers (no tracking scripts, no server-side
  pageview logging beyond standard access logs)

Say it in the README and the acceptable-use page: "no analytics on you or
your readers" is a feature we get by doing nothing, forever.

### Deferred (modeled-for, not built)
- `unlisted` post state
- Admin UI for account creation
- Username change + redirects
- Author-facing import of the Phase 13 export zip (export ships first)
- Images, tags, comments — explicitly out of scope for v1
  (RSS was on this list; promoted to the Phase 12 sketch above.
  Archive/search/scheduling similarly promoted to Smaller sketches.)

**Suggested pickup order across the sketches (2026-07-11):** settle the
Markdown/body_html caching decision first (it keeps accruing dependents:
feeds, sitemap, search) → Phase 13 export → Phase 14 hardening → Phase 11
invites → Phase 12 feed (+ microformats/sitemap riders) → archive page →
the rest as mood strikes. Phases 10 (appearance) and the other smaller
sketches slot in anywhere.

---

## 8. Open decisions before Phase 0

Small, but they touch setup:

1. **App name** — keeps working name `simple-blog`, or something you'd actually ship
   under? (Threads through namespace + repo.)
2. **Database** — SQLite for local dev (my recommendation), or stand up MySQL in Docker
   now to mirror eventual production? Note our infra memo: the Ondrej PHP PPA is
   unreachable inside Docker here, so PHP runs locally regardless — SQLite keeps Phase 0
   friction near zero.
3. **Markdown sanitization approach** — CommonMark with raw-HTML input disabled (simplest,
   safe, but authors can't embed raw HTML — fine for you), vs. allow-HTML-then-sanitize
   (more flexible, more surface). I lean strongly toward **disable raw HTML** for v1.
4. **Test framework** — Pest (modern, expressive, what most new Laravel projects use) vs.
   PHPUnit (classic). Either is fine; Pest is the pleasant default.

---

*This plan is a living document. Refine it freely — the point is to steer against it,
not to treat it as fixed.*
