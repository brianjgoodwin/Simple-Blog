<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\RedirectResponse;

/**
 * Publishing a post is modeled as creating/removing its "published" state:
 *   POST   /dashboard/posts/{post}/publish   -> publish   (store)
 *   DELETE /dashboard/posts/{post}/publish   -> unpublish (destroy)
 *
 * Both are gated by the 'update' policy ability: it's your post to publish.
 */
class PublishController extends Controller
{
    /**
     * Publish a draft.
     */
    public function store(Post $post): RedirectResponse
    {
        $this->authorize('update', $post);

        $post->publish();

        return redirect()
            ->route('posts.edit', $post)
            ->with('status', 'Post published.');
    }

    /**
     * Return a published post to draft.
     */
    public function destroy(Post $post): RedirectResponse
    {
        $this->authorize('update', $post);

        $post->unpublish();

        return redirect()
            ->route('posts.edit', $post)
            ->with('status', 'Post moved back to draft.');
    }
}
