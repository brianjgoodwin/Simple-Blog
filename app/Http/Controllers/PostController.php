<?php

namespace App\Http\Controllers;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Support\SlugGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class PostController extends Controller
{
    public function __construct(private readonly SlugGenerator $slugs)
    {
    }

    /**
     * The author's dashboard: their drafts and published posts.
     */
    public function index(): View
    {
        $author = Auth::user();

        // The dashboard only shows titles and dates — don't load every post's
        // full Markdown body just to render a list (it grows forever).
        return view('posts.index', [
            'drafts' => $author->posts()->draft()
                ->select(['id', 'title', 'slug', 'updated_at'])
                ->latest('updated_at')->get(),
            'published' => $author->posts()->published()
                ->select(['id', 'title', 'slug', 'published_at'])
                ->latest('published_at')->get(),
        ]);
    }

    /**
     * The new-post form.
     */
    public function create(): View
    {
        return view('posts.create');
    }

    /**
     * Store a new post (always as a draft; publishing is a separate action).
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
        ]);

        $author = Auth::user();

        $post = new Post($validated); // title, body (fillable)
        $post->user_id = $author->id;
        $post->slug = $this->slugs->generate($author, $validated['title']);
        $post->status = PostStatus::Draft;
        $post->save();

        return redirect()
            ->route('posts.edit', $post)
            ->with('status', 'Draft saved.');
    }

    /**
     * The edit form for one of the author's posts.
     */
    public function edit(Post $post): View
    {
        $this->authorize('update', $post);

        return view('posts.edit', ['post' => $post]);
    }

    /**
     * Update one of the author's posts.
     *
     * The slug is only regenerated while the post is still a draft; once
     * published, the slug is frozen (its public URL must never break).
     */
    public function update(Request $request, Post $post): RedirectResponse
    {
        $this->authorize('update', $post);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
        ]);

        $post->fill($validated); // title, body

        if (! $post->isPublished()) {
            $post->slug = $this->slugs->generate(Auth::user(), $validated['title'], $post->id);
        }

        $post->save();

        return redirect()
            ->route('posts.edit', $post)
            ->with('status', 'Saved.');
    }

    /**
     * Delete one of the author's posts.
     */
    public function destroy(Post $post): RedirectResponse
    {
        $this->authorize('delete', $post);

        $post->delete();

        return redirect()
            ->route('dashboard')
            ->with('status', 'Post deleted.');
    }
}
