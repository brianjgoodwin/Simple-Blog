# Simple Blog — Design & Roadmap

**Status:** live, and released "for real."

What this app is, how it's built today, and where it's going. The chronological
record of what was built and why lives in [BUILD-LOG.md](BUILD-LOG.md); this doc
is the current design and the forward plan.

> A living document. Steer against it — don't treat it as fixed.

---

## What it is

A small, invite-only home for writing. Each author gets one account and one blog
namespaced under their username. Authors write **Markdown** (no images), manage
drafts and published posts, and edit two pages (About, Links). Readers browse an
author's published posts at clean public URLs. Deliberately minimal — "a simple
way to put words online," not a blogging platform. The closest neighbours are
Bear Blog and Mataroa.

---

## Principles

The restraint **is** the product. These aren't limitations to fix later; they're
the differentiator.

- **Markdown only, no images.** The words are the point. Markdown is the
  canonical stored form, which is what makes export and longevity real.
- **No analytics, no tracking, no ads.** We don't watch you write, and we don't
  watch anyone read. Public pages make **zero third-party requests** — the CSP
  enforces it.
- **Public pages work with JavaScript disabled.** Curl-able, archivable,
  readable in 20 years. Locked so it stays true on purpose.
- **Your words are yours.** Export everything as Markdown at any time; deleting
  your account removes it. Leaving is always an option.
- **One account = one blog.** Invite-only; no open self-serve registration
  (yet — see Roadmap).
- **404, never 403, for anything non-public.** A draft, an unknown user, or a
  suspended blog is indistinguishable from one that never existed.

### Deliberately never
Stated so future-us doesn't add one innocently. These are the mechanisms by
which walled gardens made writing performative:

- View counters, likes, reactions, follower counts
- Trending / recommended / algorithmic anything
- Analytics on authors or readers (no tracking scripts; no server-side pageview
  logging beyond standard access logs)
- A `<meta name="keywords">` field — ignored by search engines since ~2009;
  offering it would be settings-theatre

"No analytics on you or your readers" is a feature we get by doing nothing,
forever — and it's said out loud on the privacy and acceptable-use pages.

---

## Architecture (as built)

### URL map

| URL | Access | Purpose |
|---|---|---|
| `/@{username}` | public | Blog home — published posts, newest first, 5/page |
| `/@{username}/{slug}` | public | A single published post (permalink) |
| `/@{username}/about`, `/links` | public | The two editable pages |
| `/@{username}/archive` | public | Every published title, grouped by year |
| `/@{username}/search?q=` | public | Full-text-ish search over published posts |
| `/@{username}/feed` | public | Atom feed (published only, capped at 20) |
| `/@{username}/sitemap.xml` | public | Per-blog sitemap |
| `/`, `/acceptable-use`, `/privacy` | public | Host landing + static policy pages |
| `/login`, `/logout`, `/register` | public | Breeze auth; register is invite-gated |
| `/dashboard` | author | Drafts, published, pages, appearance, export |
| `/dashboard/posts/{create,edit,preview}` | author | Composer (live preview, autosave) |
| `/dashboard/pages/{slug}/edit` | author | Edit About / Links |
| `/dashboard/{appearance,export}` | author | Theme/font/description; Markdown zip |

The `@` prefix on public routes prevents any collision with app routes; reserved
words (`about`, `links`, `archive`, `search`, `feed`, `sitemap.xml`) are declared
before the catch-all `{slug}`.

### Data model

- **`users`** (extends Breeze) — `name`, immutable `username` (`^[a-z0-9_]+$`,
  slug-safe, in URLs), `email`, `password`; `suspended_at` (nullable, **not
  fillable**); `theme` + `font` (enum-backed, not fillable); `description`
  (nullable, ≤200, the blog tagline). Rules live once in `User::usernameRules()`;
  About/Links seeded via `User::seedDefaultPages()`.
- **`posts`** — `user_id` (FK, indexed), `title`, `slug` (unique per user, frozen
  at first publish), `body` (Markdown source, canonical), `body_html` (cached
  render, nullable), `status` (`draft`|`published`), `published_at` (set on first
  publish, retained on unpublish). Indexes: `(user_id, slug)` unique,
  `(user_id, status, published_at)` for the public listing.
- **`pages`** — `user_id`, `slug` (`about`|`links`), `body`. Unique
  `(user_id, slug)`.
- **`invites`** — `code` (unique, plaintext), `note`, `used_at`, `used_by_id`.
  Valid iff `used_at IS NULL`.

### Rendering pipeline (the #1 XSS surface)
All public Markdown flows through `App\Support\Markdown` (GitHub-flavored
CommonMark, `html_input => 'strip'`, `allow_unsafe_links => false`, headings
shifted down one level so no body emits an `<h1>`). It renders **once**, into the
cached `body_html`, via a `Post` `saving` hook — the single write path, so the
cache can't drift from the source. `posts:rerender` rebuilds all rows after a
pipeline change. The composer preview and the feed use the exact same pipeline.

### Security posture
Ownership via `PostPolicy`/`PagePolicy` (never ad-hoc `if`s); mass-assignment
guards on `user_id`, `slug`, `status`, `suspended_at`, `theme`, `font`;
validation on every write; CSRF from server-rendered Breeze forms; 404-not-403 on
public routes; a strict CSP on the public surface; invite consumption fenced by a
guarded `UPDATE ... WHERE used_at IS NULL`; register routes throttled.

### Performance
Every public query is index-covered. Public reads select narrow (no `body`
column — the views render `body_html`). The feed and the post permalink support
conditional GET (`Last-Modified` + 304). See BUILD-LOG for what was deliberately
*not* cached and why.

### Tech stack
Laravel 13 + Breeze (Blade + Tailwind), Alpine.js (composer only), PHP 8.4,
`league/commonmark`, Pest. SQLite in both local dev and production.

---

## Roadmap

Nothing here blocks anything; sequence by mood and by what moves the product
toward "a couple hundred paying users." Grouped by intent, not priority.

### Near-term sketches (designed, not built)

- **Post scheduling.** `status` + `published_at` already model it: publish with a
  future timestamp; public scopes become `published_at <= now()`; slug freezes at
  *scheduling*. Cheap mechanically, subtle at the edges — **read the
  complications before starting** (recorded in full in BUILD-LOG's spirit, summary
  here):
  - `scopePublished` is status-only today; adding a time gate ripples to every
    public surface at once (good) *and* to the dashboard's "Published" list, which
    then needs a distinct **Scheduled** bucket (the "third state").
  - **It breaks the feed's conditional GET as built.** A scheduled post crossing
    `now()` is a content change no *write* triggers, and its `updated_at` is old,
    so `max(updated_at)` won't advance and 304-holding readers never see it. Fix:
    compute the feed timestamp as `max(greatest(published_at, updated_at))` over
    live posts, in the same change. Do this one **last and deliberately** — it's
    the only sketch that reaches back into the feed's caching logic.
- **Author-facing import.** The natural sequel to export, and reframed as an
  **acquisition** lever (it removes switching cost for people leaving WordPress /
  Medium / Substack). Architecture: one **native importer** that accepts the
  export zip (Markdown + YAML front-matter) — which also keeps the export format
  honest via a round-trip test — with **format converters** (e.g. WordPress WXR)
  that translate a foreign export into that native shape, then hand off. Because
  imports go through the normal model layer, `body_html` renders and the XSS
  pipeline sanitizes automatically. The hard call is images (locked out): convert
  `<img>` to a plain link to the original, and report exactly what was
  transformed. Ship a **dry-run report** before writing anything.
- **Light SEO opt-out.** A per-author `<meta name="robots" content="noindex">`
  toggle (and exclusion from the sitemap) — deliberately **not** a `robots.txt`
  Disallow, which would block the crawl so the noindex is never seen, and would
  turn `robots.txt` into a public list of who opted out. (The description meta /
  OG / sitemap that this sketch also proposed are already built.)
- **Accessibility leftovers.** Dropdown menu semantics (the panel is plain links)
  and a `prefers-reduced-motion` fallback for modal/dropdown transitions — both
  low-impact, deferred from the Phase 9 follow-up.

### Commercialization ("release for real")
The blogging tool is essentially done; the *business* is the roadmap. At ~200
users × ~$30/yr this is a lovely side-project — so favour **low-ops, low-support**
features. This is mostly new territory for the codebase.

- **Self-serve signup.** The invite system can *become* the beta gate, then relax
  into open (or waitlisted) registration.
- **Billing.** Laravel Cashier + Stripe — subscriptions, customer portal, trials,
  dunning. The single biggest new subsystem.
- **Transactional email that lands.** Password resets, receipts, "your card
  failed" — a real provider (Postmark), SPF/DKIM, bounces. Email becomes
  load-bearing for the first time.
- **Custom domains** — the star feature for this positioning: for a "you own your
  words" product, `yourname.com` is both the emotional payoff *and* the natural
  paid tier. Real work — Caddy on-demand TLS, a verified `domain` column,
  host-based routing coexisting with `trustHosts`, per-domain canonical URLs in
  feeds/OG. This is where the philosophy ultimately points.
- **The pricing fork.** Fewer upsell levers by design (no images, no analytics to
  gate). Cleanest fit with the ethos: **free on a subdomain / `/@you`, paid
  unlocks a custom domain** — or flat-paid with a trial. Pick one; it drives
  everything else.

### Synergistic features (fit the grain, exploit what's already built)

- **Webmentions.** The IndieWeb-native, ethos-pure alternative to comments — and
  we already emit `h-entry`/`h-card`. Replies live on the sender's own blog; we
  show backlinks. No comment DB, no moderation, no reader PII. Highest synergy;
  it's also the honest answer to "can readers respond?".
- **Reading-experience polish.** Auto table-of-contents (byproduct of the
  heading pass), reading time (`wordCount/200`), prev/next nav, **footnotes**, and
  typographic niceties (smart quotes, em-dashes, widows). All static, no-JS,
  no-tracking, pipeline-local; cheap and immediately felt for a *writing* product.
- **"Make a book of your blog" — EPUB / PDF export.** A natural, emotionally
  resonant extension of the Markdown export, and a clean paid perk. A full
  static-site export is the literal proof of "readable in 20 years."
- **POSSE** — publish here, syndicate to Mastodon/Bluesky; your site stays
  canonical and syndication brings readers back. Growth that *reinforces* "own
  your words."
- **Structure writers crave:** wiki-links / backlinks (`[[post]]`, resolved in the
  render pass — digital-garden catnip), post series/collections, and private
  `noindex` draft-share links for feedback without publishing.

### Strategic forks (decide consciously)

- **Email newsletters — stay no (recommendation).** The Substack-refugee audience
  will ask, but it's the biggest ops/ethics burden imaginable (reader PII,
  deliverability, unsubscribe compliance) and it directly contradicts "we don't
  watch readers." RSS + custom domains is the "following" story. Deciding this
  defines what the product *is*.
- **"How many read me?"** — writers will ask constantly, and the principles forbid
  tracking. The answer is a confident *"we deliberately don't,"* made into
  marketing — not a feature.
- **Discoverability vs. the privacy posture.** The landing page reveals nothing
  about who has blogs here (no directory). That protects authors but forgoes a
  network-effect channel. Probably right to keep; know the trade.
- **The "resist" list.** Custom CSS (breaks the CSP/no-JS/consistency posture,
  generates support), reader analytics, and freeform microposts-drifting-toward-
  social are the three things well-meaning users will request that would dissolve
  the differentiation. "No, and here's why" is itself a roadmap item.
- **Accessibility as positioning.** We've invested more in a11y than almost any
  blog host (modal semantics, skip links, an automated contrast guardrail, AA
  themes). *"The most accessible place to publish"* is ownable, true, and matters
  to audiences with budgets (education, institutions). A tiny per-blog
  accessibility-statement page would signal it for almost no code.

### Deferred (modeled-for, not built)
`unlisted` post state · admin UI for account creation · username change +
redirects · tags/comments (comments → webmentions, above).
