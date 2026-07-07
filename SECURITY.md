# Security notes

This is a multi-tenant, invite-only blog host: one account = one blog, public
reader routes at `/@{username}`, an auth-gated `/dashboard` for authors. In a
multi-tenant app the whole game is *tenant isolation* — author A must never be
able to read or change author B's content, and the public must never see drafts.

This file records the controls that enforce that, so a future me (or a reviewer)
can see what's load-bearing without reverse-engineering it. It was written as the
Phase 6 "security self-review" deliverable and independently re-audited by a
security-focused agent; both passes agreed the controls below hold.

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

## Known deferrals (accepted for v1)

- No rate limiting on public reader routes (it's read-only, invite-only host).
- No Content-Security-Policy header. Worth adding if this ever leaves the dev
  server — belt-and-suspenders on top of the Markdown stripping.
- `artisan serve` is a dev server (no TLS, no hardening). Production hosting is
  out of scope for this project; if deployed, put it behind a real web server.
