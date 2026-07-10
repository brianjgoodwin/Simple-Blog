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

### Deferred (modeled-for, not built)
- `unlisted` post state
- Admin UI for account creation
- Username change + redirects
- Images, tags, comments, RSS — explicitly out of scope for v1

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
