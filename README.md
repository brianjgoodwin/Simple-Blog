# Simple Blog

A small, invite-only home for writing. One account = one blog. You write
Markdown; the public reads. Deliberately minimal — *a simple way to put words
online, not a blogging platform.*

Built on Laravel 13 + Breeze (Blade + Tailwind).

## Philosophy

The restraint is the point:

- **Markdown only, no images.** The words are the point — and Markdown is the
  canonical stored form, which is what makes export and longevity real.
- **No analytics, no tracking, no ads.** We don't watch you write, and we don't
  watch anyone read. Public pages make **zero third-party requests**.
- **Works without JavaScript.** Public pages are curl-able, archivable, and
  meant to be readable in 20 years.
- **Your words are yours.** Export everything as Markdown at any time; deleting
  your account removes it.

See [PLAN.md](PLAN.md) for the full design and roadmap, and
[BUILD-LOG.md](BUILD-LOG.md) for the history and decisions behind it.

## Features

- **Writing** — a Markdown composer with live server-rendered preview, autosave
  (drafts only), and a word count. Drafts and published posts; publish/unpublish
  with slugs that freeze at first publish.
- **Public blog** at `/@{username}` — a "river" of full posts (5/page),
  per-post permalinks, an **archive** grouped by year, and **full-text search**.
- **Two editable pages** — About and Links.
- **Appearance** — seven accessible, WCAG-AA-verified themes, a serif/sans
  toggle (system fonts only), and a short blog description/tagline.
- **Syndication & discovery** — a per-blog **Atom feed** with autodiscovery and
  conditional GET, IndieWeb **microformats**, **Open Graph** tags, and a
  **sitemap**.
- **Export** — download everything you've written as a zip of Markdown files.
- **Accessibility** — a first-class concern (WCAG AA), including an automated
  theme-contrast test.
- **Operator tooling** — invite-code registration, author suspend/unsuspend, a
  strict Content-Security-Policy on public pages, and privacy / acceptable-use
  pages.

## Tech stack

- **Laravel 13** + **Breeze** (Blade + Tailwind), **Alpine.js** (composer only)
- **PHP 8.4**
- **Database:** SQLite (bundled, zero-config) — in both local dev and
  production
- **Markdown:** `league/commonmark` (GitHub-flavored, raw HTML stripped)
- **Testing:** Pest

## Local development

```bash
composer install
npm install
cp .env.example .env        # if you don't already have one
php artisan key:generate    # if APP_KEY is unset
php artisan migrate
npm run build               # or `npm run dev` while developing
php artisan serve
```

`artisan serve` binds to `127.0.0.1` only, so it's never exposed publicly. On a
remote dev box, forward the port over SSH (e.g. `LocalForward 8080
localhost:8080` in `~/.ssh/config`) and browse to it locally.

**Keeping a checkout current after `git pull`:** PHP changes are live
immediately (no restart). Only three things can go stale, each only if its
inputs changed — `composer install` (composer.lock), `php artisan migrate` (new
migrations), `npm run build` (anything in `resources/`; compiled assets live in
the un-committed `public/build/`). When in doubt run all three; if it still
looks stale, `php artisan optimize:clear` wipes the config/route/view caches.

## Creating an author account

Registration is invite-gated. Create the first account from the CLI:

```bash
php artisan author:create
```

It prompts for name, username (immutable, used in URLs), email, and password.
`--name/--username/--email/--password` flags exist for scripting, but prefer the
interactive prompt so passwords never land in shell history.

To invite others, generate a code and send the printed link:

```bash
php artisan invite:generate --note="for Dave"    # prints a /register?code=… link
php artisan invite:list                           # used / unused codes
```

## Testing

```bash
php artisan test
```

Pest; the suite covers the multi-tenancy, visibility (drafts-never-leak), XSS,
and accessibility guarantees.

## Deployment

Runs behind a Caddy reverse proxy as a systemd user service — full steps in
[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md), and backup/restore in
[docs/BACKUP.md](docs/BACKUP.md). A deploy that adds routes or migrations needs
`php artisan migrate`, `php artisan route:cache`, and `npm run build`; a change
to the Markdown pipeline needs `php artisan posts:rerender`.
