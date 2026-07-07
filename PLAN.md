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

### Phase 7 — Deploy (planned, not started)
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
