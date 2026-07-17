<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * The public, reader-facing blog at /@{username}.
 *
 * Only PUBLISHED posts are ever listed or reachable here. Drafts, unknown
 * users, unknown slugs, and SUSPENDED authors all return a 404 — never a
 * 403 — so the existence of a draft (or a suspension) is never leaked.
 */
class PublicBlogController extends Controller
{
    /**
     * The author's blog home: full posts, newest first, paginated.
     *
     * Shows the complete body of each post inline (classic-blog "river"),
     * 10 per page. Each post's title links to its own permalink for sharing.
     */
    public function home(User $author): View
    {
        $this->abortIfSuspended($author);

        // Only the columns the river renders. Since body_html landed, the
        // Markdown source `body` is dead weight on every public read — it can
        // be as large as the rendered HTML, so leaving it out roughly halves
        // the row payload for a 10-post page.
        return view('public.home', [
            'author' => $author,
            'posts' => $author->posts()
                ->published()
                ->latest('published_at')
                ->paginate(10, ['id', 'title', 'slug', 'published_at', 'body_html']),
        ]);
    }

    /**
     * A single published post.
     *
     * The published() scope is what enforces "drafts are not public": a draft
     * slug simply won't be found here, yielding a 404.
     *
     * Conditional GET, same pattern as the feed: Laravel's default
     * `Cache-Control: no-cache, private` makes browsers revalidate on every
     * visit, so answering If-Modified-Since with a 304 lets repeat readers
     * reuse their cached copy instead of re-downloading the page. Safe here
     * (unlike the home river) because unpublish/delete makes the NEXT request
     * 404 at firstOrFail, before any 304 can be served. Last-Modified takes
     * the author's timestamp into account too: a name, description, or theme
     * change alters the page shell without touching the post row.
     */
    public function post(Request $request, User $author, string $slug): Response
    {
        $this->abortIfSuspended($author);

        // No `body`: the page renders the cached body_html only.
        $post = $author->posts()
            ->published()
            ->where('slug', $slug)
            ->firstOrFail(['id', 'title', 'slug', 'published_at', 'updated_at', 'body_html']);

        $response = new Response();
        $response->setLastModified($post->updated_at->max($author->updated_at));

        if ($response->isNotModified($request)) {
            return $response; // 304 — the reader's cached copy is current
        }

        return $response->setContent(view('public.post', [
            'author' => $author,
            'post' => $post,
        ])->render());
    }

    /**
     * The blog's Atom feed: published posts only, newest first, capped at 20.
     *
     * Conditional GET is the point of the design. Readers poll this forever;
     * we compute Last-Modified from the newest published-post timestamp with a
     * single aggregate query (no bodies loaded) and answer If-Modified-Since
     * with a bare 304 before rendering anything. Only a genuinely-changed feed
     * pays the cost of loading and serializing 20 posts.
     *
     * The Last-Modified value is max(updated_at), matching the feed's honest
     * <updated>: an edit re-stamps the feed and may re-surface a post, which we
     * accept as truthful (a locked call — see PLAN.md Phase 12).
     */
    public function feed(Request $request, User $author): Response
    {
        $this->abortIfSuspended($author);

        $lastModified = $author->posts()->published()->max('updated_at');

        $response = new Response();
        if ($lastModified !== null) {
            $response->setLastModified(Carbon::parse($lastModified));
        }

        if ($response->isNotModified($request)) {
            return $response; // 304, empty body — nothing further queried
        }

        // Only what the Atom template serializes — no Markdown source.
        $posts = $author->posts()
            ->published()
            ->latest('published_at')
            ->limit(20)
            ->get(['id', 'title', 'slug', 'published_at', 'updated_at', 'body_html']);

        return $response
            ->setContent(view('feed.atom', [
                'author' => $author,
                'posts' => $posts,
                // Atom requires a feed-level <updated>; fall back to the
                // author's creation time for a blog with nothing published yet.
                'updated' => $lastModified ? Carbon::parse($lastModified) : $author->created_at,
            ])->render())
            ->header('Content-Type', 'application/atom+xml; charset=UTF-8');
    }

    /**
     * The archive: every published post's title, newest first, grouped by year.
     *
     * The river (home) is about recent writing; the archive is the whole body
     * of work at a glance. Titles and dates only — cheap enough to skip
     * pagination. Same published() scope and suspended guard as everywhere
     * public, so drafts never appear.
     */
    public function archive(User $author): View
    {
        $this->abortIfSuspended($author);

        $postsByYear = $author->posts()
            ->published()
            ->latest('published_at')
            ->get(['title', 'slug', 'published_at'])
            ->groupBy(fn (Post $post) => $post->published_at->year);

        return view('public.archive', [
            'author' => $author,
            'postsByYear' => $postsByYear,
        ]);
    }

    /**
     * Full-text-ish search across the author's published posts.
     *
     * A plain LIKE scope (Post::scopeSearch) composed with published(), so
     * drafts are never searchable. A blank query renders the prompt rather than
     * matching everything. abortIfSuspended first, like every public route.
     */
    public function search(Request $request, User $author): View
    {
        $this->abortIfSuspended($author);

        $term = trim((string) $request->query('q', ''));

        // The WHERE still matches against `body`; only the SELECT is narrowed
        // (results render title, date, and a body_html-derived excerpt).
        $results = $term === ''
            ? null
            : $author->posts()
                ->published()
                ->search($term)
                ->latest('published_at')
                ->paginate(10, ['id', 'title', 'slug', 'published_at', 'body_html'])
                ->withQueryString();

        return view('public.search', [
            'author' => $author,
            'term' => $term,
            'results' => $results,
        ]);
    }

    /**
     * A per-blog sitemap.xml: the home page, About, Links, and every published
     * post. Same published() scope and suspended-author guard as everything
     * else public, so it can never list a draft or a suspended blog.
     */
    public function sitemap(User $author): Response
    {
        $this->abortIfSuspended($author);

        $posts = $author->posts()
            ->published()
            ->latest('published_at')
            ->get(['slug', 'updated_at']);

        return response()
            ->view('sitemap', ['author' => $author, 'posts' => $posts])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    /**
     * The public About page.
     */
    public function about(User $author): View
    {
        return $this->page($author, 'about');
    }

    /**
     * The public Links page.
     */
    public function links(User $author): View
    {
        return $this->page($author, 'links');
    }

    /**
     * Render a public page (About or Links) for the author.
     */
    private function page(User $author, string $slug): View
    {
        $this->abortIfSuspended($author);

        $page = $author->pages()->where('slug', $slug)->firstOrFail();

        return view('public.page', [
            'author' => $author,
            'page' => $page,
        ]);
    }

    /**
     * A suspended author's blog is publicly indistinguishable from one that
     * never existed. Every public entry point calls this FIRST, before any
     * content query — a new public route (e.g. the Phase 12 feed) must do
     * the same.
     */
    private function abortIfSuspended(User $author): void
    {
        abort_if($author->isSuspended(), 404);
    }
}
