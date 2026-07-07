# Deployment plan — Simple Blog behind Caddy

**Status:** Not started. This is a plan to execute later, not a record of a done
thing. Nothing below has been applied yet.

The goal: put Simple Blog on a public HTTPS subdomain on the dev server, reusing
the exact pattern Puzzlebox already runs. Puzzlebox is the working reference —
when in doubt, copy what it does.

## Decisions — LOCKED (2026-07-07)

1. **Go public — yes, but not on a deadline.** The goal is a live public deploy
   (systemd + Caddy + Cloudflare DNS + trusted proxies). Whether that's "today" or
   "later" depends on the work being ready, not a date. Execute the checklist below
   when ready; the last two steps (Cloudflare DNS + the final go-live verify) are
   Brian's deliberate call.

2. **Subdomain — `simpleblog.brianjgoodwin.dev`.** Brian adds the Cloudflare DNS
   record (I can't touch DNS).

3. **Port — 8001.** Puzzlebox owns 8000; 8080 is the current SSH-tunnel dev port.
   8001 keeps Simple Blog's production service clear of both.

4. **Database — keep SQLite in production, indefinitely.** Simple Blog's workload is
   read-heavy and near-zero write-concurrency (a few invited authors saving posts by
   hand; readers only read). That is SQLite's best case, not a compromise — no Docker,
   no MySQL. The migration path to MySQL/Postgres stays open at near-zero cost because
   the app is all Eloquent (no SQLite-specific SQL). Revisit ONLY if a write-heavy
   feature lands (comments, reactions, per-pageview view counts, realtime) — write
   concurrency is the trigger, not data size or traffic. Requirements when deploying:
   - `database/` dir and the `.sqlite` file must be writable by the service user.
   - The file must NOT be web-reachable (it isn't — it lives outside `public/`).
   - **WAL mode must be on** (readers never block the writer). Laravel 11+ defaults
     `journal_mode=WAL` for new SQLite connections — but confirm, don't assume. See
     the verify step.

## How the Puzzlebox pattern works (the reference)

- **One Caddy container** at `~/developer/caddy-proxy` owns ports 80/443 and
  terminates TLS for every subdomain via Let's Encrypt + Cloudflare DNS challenge.
- **The Laravel app runs on the host** as a `systemd --user` service doing
  `php artisan serve --host=0.0.0.0 --port=<port>`, kept alive across
  logout/reboot by linger.
- **Caddy → host app** over `host.docker.internal:<port>` (Docker gateway
  `172.18.0.1`), passing `trusted_proxies 172.18.0.0/16`.
- **Laravel trusts** `X-Forwarded-*` only from `172.18.0.0/16`, so it generates
  correct `https://` URLs without trusting arbitrary client headers.

## Execution checklist (do these in order, later)

### 1. Laravel: trust the proxy
In `bootstrap/app.php`, inside `->withMiddleware(...)`, add — exactly as Puzzlebox
does at `~/developer/puzzlebox/bootstrap/app.php:14`:

```php
$middleware->trustProxies(at: '172.18.0.0/16');
```

Without this, Laravel behind Caddy generates `http://` URLs and may mishandle
redirects. This is the one non-optional app-side change.

### 2. Production env
- `APP_URL=https://<subdomain>` (currently `http://localhost` in `.env.example`)
- `APP_ENV=production`, `APP_DEBUG=false`
- `php artisan config:cache` after setting these (and `route:cache`, `view:cache`)
- `npm run build` — Laravel/Vite needs built assets in production (unlike Kirby).
  Rebuild on every deploy that touches Blade/CSS/JS. See the Laravel-build-step note.

### 3. systemd user service
Create `~/.config/systemd/user/simple-blog.service`, modeled on
`~/.config/systemd/user/puzzlebox.service`. SQLite means NO `ExecStartPre` docker
line (Puzzlebox has one only because it starts MySQL):

```ini
[Unit]
Description=Simple Blog Laravel Application
After=network.target

[Service]
Type=simple
WorkingDirectory=/home/brian/developer/simple-blog
ExecStart=/usr/bin/php artisan serve --host=0.0.0.0 --port=8001
Restart=on-failure
RestartSec=5

[Install]
WantedBy=default.target
```

Then:
```bash
systemctl --user daemon-reload
systemctl --user enable --now simple-blog
loginctl enable-linger brian   # already enabled if Puzzlebox survives reboot; harmless to re-run
```

SECURITY NOTE: `--host=0.0.0.0` binds the app to ALL interfaces, not just
localhost. That is only safe because UFW must restrict port 8001 to the
caddy-proxy Docker network (see step 5). Do not skip the UFW rule — otherwise the
raw, TLS-less app is reachable on the server's public IP. Puzzlebox does exactly
this restriction for 8000; mirror it for 8001.

### 4. Caddyfile block
Add to `~/developer/caddy-proxy/Caddyfile`, mirroring the `puzzlebox.brianjgoodwin.dev`
block:

```
simpleblog.brianjgoodwin.dev {
	import cloudflare
	import security_headers
	reverse_proxy host.docker.internal:8001 {
		trusted_proxies 172.18.0.0/16
	}
}
```

`(cloudflare)` and `(security_headers)` snippets already exist in that Caddyfile —
the security_headers snippet gives HSTS, X-Frame-Options, X-Content-Type-Options,
Referrer-Policy, and strips X-Powered-By for free.

Reload Caddy (from `~/developer/caddy-proxy`):
```bash
docker compose exec caddy caddy reload --config /etc/caddy/Caddyfile
# or: docker compose restart caddy
```

### 5. UFW — restrict the app port
Port 8001 must be reachable ONLY from the caddy-proxy Docker network
(`172.18.0.0/16`), never the internet. Check how Puzzlebox's 8000 rule is written
and mirror it:
```bash
sudo ufw status verbose            # see the existing 8000 rule
# add the equivalent for 8001, allowing only from 172.18.0.0/16
sudo ufw status verbose            # verify 8001 is not open to the world
```

### 6. Cloudflare DNS (you, manually)
Add a proxied A/CNAME record for `simpleblog.brianjgoodwin.dev` pointing at the
server, same as the other subdomains. The DNS challenge for TLS needs the
Cloudflare API token that's already in `~/developer/caddy-proxy/.env`.

### 7. Verify
```bash
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8001/          # local app
curl -s -o /dev/null -w "%{http_code}\n" https://simpleblog.brianjgoodwin.dev/   # through Caddy

# Confirm SQLite is in WAL mode (readers never block the writer). Expect: wal
php artisan tinker --execute="echo DB::select('PRAGMA journal_mode')[0]->journal_mode;"
```
If that prints anything other than `wal`, set it explicitly — either in
`config/database.php` under the sqlite connection (`'journal_mode' => 'WAL'` on
Laravel 11+) or once via `PRAGMA journal_mode=WAL;` (WAL persists on the file).

Then browse it: landing page, a real `/@{username}` blog, and confirm the address
bar shows a valid cert and `https://` URLs everywhere (proves trustProxies works).

## Deploying updates later
```bash
cd ~/developer/simple-blog
git pull
npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
systemctl --user restart simple-blog
```

## Open question worth revisiting
Simple Blog's own `SECURITY.md` lists "no Content-Security-Policy header" as a
v1 deferral. Once behind Caddy, a CSP could be added as another header in the
Caddy block (or a Caddy snippet) — belt-and-suspenders on top of the Markdown
HTML-stripping. Low priority, but this is where it would go.
