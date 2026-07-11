# Security notes

This is a multi-tenant, invite-only blog host: one account = one blog, public
reader routes at `/@{username}`, an auth-gated `/dashboard` for authors. In a
multi-tenant app the whole game is *tenant isolation* — author A must never be
able to read or change author B's content, and the public must never see drafts.

This file records the controls that enforce that, so a future me (or a reviewer)
can see what's load-bearing without reverse-engineering it. It was written as the
Phase 6 "security self-review" deliverable and independently re-audited by a
security-focused agent; both passes agreed the controls below hold. A third audit
after going live (2026-07-07) found four minor issues, all fixed — see the
**Audit log** at the bottom.

## Threat model (what we actually defend against)

| Threat | Control |
|---|---|
| Author A edits/deletes/publishes Author B's post | `PostPolicy` — every mutating controller action calls `authorize()` |
| Author A edits Author B's page | Page lookup is scoped to `Auth::user()->pages()`, plus `PagePolicy` |
| A draft is visible/reachable publicly | Public queries use the `published()` scope; misses are 404 |
| Existence of a draft is leaked via 403 | Public routes never authorize — unknown = 404, never 403 |
| XSS via author Markdown shown to readers | All bodies render through `App\Support\Markdown` (raw HTML stripped) |
| Mass-assignment sets `user_id`/`slug`/`status` from request input | Those fields are not fillable; set server-side only |
| Open registration | No `register` route or controller; accounts via `author:create` only |
| CSRF on dashboard forms | Default `web` middleware group + Breeze `@csrf`; no exclusions |
| SQL injection | Eloquent everywhere; no raw SQL (`whereRaw`, `DB::select`, etc.) |

## The load-bearing pieces

### Ownership authorization — `app/Policies/`
`PostPolicy` and `PagePolicy` each reduce to `$user->id === $model->user_id`.
Every mutating action in `PostController`, `PublishController`, and
`PageController` calls `$this->authorize(...)` before touching the model.

Note the two patterns, both valid:
- **Posts** use global route-model binding (`{post}` resolves by id across the
  whole table). The *policy* is the sole gate — so the policy must be right, and
  the authorize() call must be present in every method. It is. Tested in
  `PostManagementTest` ("a user cannot edit another user's post").
- **Pages** are stronger: `PageController::resolvePage()` queries
  `Auth::user()->pages()->where('slug', $slug)->firstOrFail()`, so another
  author's page is unreachable (404) *before* the policy even runs — defense in
  depth. The slug is also allow-listed to `about`/`links`.

### Markdown rendering — `app/Support/Markdown.php`
This is the app's main XSS surface: author-written Markdown becomes HTML on
public pages. `Str::markdown(..., ['html_input' => 'strip',
'allow_unsafe_links' => false])` removes raw HTML (`<script>`, `onerror=`, …)
and neutralizes `javascript:`/`data:` URLs. **Every** public body render goes
through here — `public/home`, `public/post`, `public/page`. Never echo a raw
body anywhere else.

Subtle but deliberate: the views use `{{ }}` (escaped), not `{!! !!}`.
`Markdown::toHtml` returns an `HtmlString` (which is `Htmlable`), so Blade emits
it raw *when it's that type* — but if the method ever regressed to returning a
plain string, the output would fail **closed** (escaped, ugly) rather than open
(raw injection). Tested in `PublicBlogTest` (script tag / `javascript:` link /
`onerror` image are all stripped).

### Mass-assignment — `app/Models/`
- `Post`: `#[Fillable(['title', 'body'])]`. `user_id`, `slug`, `status`,
  `published_at` are set server-side in `PostController` only.
- `Page`: `#[Fillable(['slug', 'body'])]`. `user_id` is intentionally *not*
  fillable — pages are only created via `$user->pages()->create(...)`, and the
  relationship sets the owner itself. So request input can never set a page's
  owner even if a future controller mass-assigns validated input.
- `User`: `username` is fillable (needed by `author:create`) but the profile
  form validates only `name`/`email`, so it can't be changed via the web —
  usernames are immutable in practice.

### 404-not-403 on public routes — `app/Http/Controllers/PublicBlogController.php`
`firstOrFail()` + the `published()` scope means drafts, unknown authors, and
unknown slugs are all indistinguishable "not found". No public route ever
returns 403 (which would confirm a resource exists). The username route pattern
`[a-z0-9_]+` plus the literal `@` prefix keeps junk paths and app-route
collisions (`/login`, `/dashboard`) from ever reaching the DB.

### Production deployment controls (added when the app went live 2026-07-07)
The app is LIVE at `https://simpleblog.brianjgoodwin.dev` (prod checkout at
`/srv/www/simpleblog`, port 8001, behind the shared Caddy proxy). The
deployment-side controls:
- **TLS** terminated by Caddy (Let's Encrypt); HTTP→HTTPS redirect at the edge.
  Security headers (HSTS, X-Frame-Options, nosniff, Referrer-Policy) from Caddy.
- **`trustProxies(at: '172.18.0.0/16')`** — trusts `X-Forwarded-*` only from the
  Caddy Docker network, so HTTPS URLs generate correctly. (`bootstrap/app.php`)
- **`trustHosts(at: ['simpleblog.brianjgoodwin.dev'])`** — rejects spoofed
  Host / X-Forwarded-Host (400), so a compromised co-resident container can't
  poison generated URLs. Enforced in non-local envs only. (`bootstrap/app.php`)
- **UFW** scopes port 8001 to `172.18.0.0/16` only — the raw, TLS-less app is
  never reachable from the public internet, only via Caddy.
- **Secrets**: prod `.env` is `600`, app dir `750` — not readable by other shell
  users on the box. `APP_ENV=production`, `APP_DEBUG=false`, own `APP_KEY`.
- **`SESSION_SECURE_COOKIE=true`** set explicitly in prod (not left to
  scheme-detection). Session cookie is `secure; httponly; samesite=lax`.
- Full deploy runbook: `docs/DEPLOYMENT.md`. Proxy reference:
  `~/documents/caddy-docker-setup.md`.

## Known deferrals (accepted for v1)

- No rate limiting on public reader routes (it's read-only, invite-only host).
  Login and password-reset ARE throttled (Breeze: 5/min login, 6/min reset).
- ~~No Content-Security-Policy header.~~ DONE 2026-07-11 (Phase 14): a strict
  CSP (`default-src 'none'` + narrow allowances) is set by app middleware on
  all reader-facing routes — see `PublicContentSecurityPolicy` for the policy,
  its rationale, and the accepted binding-layer 404 gap. The authenticated
  dashboard is deliberately not covered (Alpine needs eval-style evaluation;
  Breeze loads webfonts) — a weaker dashboard policy remains open.
- **`trustProxies` still trusts the whole `172.18.0.0/16`**, not just Caddy's
  gateway `172.18.0.1`. `trustHosts` closes the exploitable path, so residual risk
  is low, but narrowing the range is the more principled fix — do it next time the
  proxy config is touched, and especially before running any *untrusted* container
  on that Docker network.

## Operator controls (Phase 14, 2026-07-11)

Added before invite codes (Phase 11) ever open registration — the moment
other people can publish here, the operator needs a lever:

- **Suspension:** `php artisan author:suspend {username}` / `author:unsuspend`.
  One nullable `suspended_at` timestamp (not fillable — can never be set from
  request input). A suspended author's blog 404s everywhere public
  (indistinguishable from never existing — same posture as drafts), login
  fails with the same generic error as a wrong password, and existing
  sessions are ended on the next authenticated request
  (`EnsureAuthorNotSuspended` middleware). Content is untouched; unsuspend
  restores everything.
- **CSP:** see the deferrals list above — now implemented for the public
  surface.
- **Acceptable use:** `/acceptable-use`, linked from the landing page. Makes
  suspension a policy action, and states the no-analytics posture publicly.

## Audit log

### 2026-07-07 — post-deployment audit (app-code agent + infra audit)
Ran after go-live. Core app controls (ownership, XSS, mass-assignment, injection,
enumeration, registration-disabled) all re-confirmed clean — several verified by
execution, not just reading. `composer audit` clean. Four minor issues found and
fixed the same day (commit `98f9ef0` + a chmod):

| # | Severity | Issue | Fix | Verified |
|---|---|---|---|---|
| 1 | HIGH | Prod `APP_KEY` readable by other shell users (`/srv/www` was world-traversable, `.env` was `664`) | `chmod 600 .env`, `chmod 750` app dir | `guest` re-tested → denied |
| 2 | MEDIUM | Host-header injection: app on `0.0.0.0:8001` + `trustProxies('/16')` let an in-range container spoof `X-Forwarded-Host` to poison reset-link URLs | `trustHosts` allow-list | Live: spoofed Host → 400 |
| 3 | LOW | `/forgot-password` returned "can't find a user" for unknown emails (enumeration) | Always return generic `RESET_LINK_SENT` | Live: broker `passwords.user` → response `passwords.sent` |
| 4 | LOW | `SESSION_SECURE_COOKIE` unset (relied on scheme detection) | Set `=true` in prod `.env` | Live: cookie `secure` |

Residual: finding #2's deeper fix (narrow `trustProxies` to `172.18.0.1`) is
deferred — see the deferrals list above.
