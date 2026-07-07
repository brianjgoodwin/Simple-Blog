<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\View\View;

/**
 * The public, reader-facing blog at /@{username}.
 *
 * Only PUBLISHED posts are ever listed or reachable here. Drafts, unknown
 * users, and unknown slugs all return a 404 — never a 403 — so the existence
 * of a draft is never leaked.
 */
class PublicBlogController extends Controller
{
    /**
     * The author's blog home: their published posts, newest first.
     */
    public function home(User $author): View
    {
        return view('public.home', [
            'author' => $author,
            'posts' => $author->posts()
                ->published()
                ->latest('published_at')
                ->get(),
        ]);
    }

    /**
     * A single published post.
     *
     * The published() scope is what enforces "drafts are not public": a draft
     * slug simply won't be found here, yielding a 404.
     */
    public function post(User $author, string $slug): View
    {
        $post = $author->posts()
            ->published()
            ->where('slug', $slug)
            ->firstOrFail();

        return view('public.post', [
            'author' => $author,
            'post' => $post,
        ]);
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
        $page = $author->pages()->where('slug', $slug)->firstOrFail();

        return view('public.page', [
            'author' => $author,
            'page' => $page,
        ]);
    }
}
