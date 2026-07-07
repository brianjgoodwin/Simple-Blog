<?php

namespace App\Support;

use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Generates a URL slug from a post title, unique within one author's posts.
 *
 * Slugs are unique per user (two authors may each have /@them/hello), so
 * uniqueness is always checked scoped to the author.
 */
class SlugGenerator
{
    /**
     * Build a slug for $title under $author that no other of their posts uses.
     *
     * $ignorePostId lets an edit keep its own slug without colliding with itself.
     */
    public function generate(User $author, string $title, ?int $ignorePostId = null): string
    {
        $base = Str::slug($title);

        // A title of only non-slug characters (e.g. "!!!") slugs to ''.
        // Fall back to something stable rather than an empty URL segment.
        if ($base === '') {
            $base = 'post';
        }

        $slug = $base;
        $suffix = 2;

        while ($this->slugTaken($author, $slug, $ignorePostId)) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * Is $slug already used by another post belonging to $author?
     */
    private function slugTaken(User $author, string $slug, ?int $ignorePostId): bool
    {
        return $author->posts()
            ->where('slug', $slug)
            ->when($ignorePostId !== null, fn ($query) => $query->whereKeyNot($ignorePostId))
            ->exists();
    }
}
