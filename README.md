# Simple Blog

A minimal, multi-tenant, Markdown-only blogging app. One account = one blog.
Invited authors only; the public reads. Built on Laravel 13 + Breeze (Blade).

See `PLAN.md` for the full design, decisions, and build phases.

## Requirements

- PHP 8.4
- Composer
- Node.js + npm (for building front-end assets)
- SQLite (bundled; no separate DB server needed for local dev)

## Setup

```bash
composer install
npm install
cp .env.example .env        # if you don't already have a .env
php artisan key:generate    # if APP_KEY is not set
php artisan migrate
npm run build               # or `npm run dev` while developing
```

## Running locally

Port 8000 is used by another service on the dev server (a Docker container),
so the blog runs on **8080** to avoid the collision:

```bash
php artisan serve --port=8080
```

`artisan serve` binds to `127.0.0.1` only, so the app is not exposed publicly.
Reach it from your Mac over an SSH tunnel — add this to `~/.ssh/config`:

```
Host development
    # ...existing HostName / User...
    LocalForward 8080 localhost:8080
```

Then `ssh development` and browse to <http://localhost:8080>.

## Creating an author account

Registration is disabled by design (invite-only). Create accounts from the CLI:

```bash
php artisan author:create
```

It prompts for name, username (immutable, used in URLs), email (used to log
in), and password. For scripting/tests you can pass `--name`, `--username`,
`--email`, and `--password`, but prefer the interactive prompt for real
accounts so the password never lands in your shell history.

## Testing

```bash
php artisan test
```

Tests use Pest.
