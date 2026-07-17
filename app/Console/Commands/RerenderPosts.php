<?php

namespace App\Console\Commands;

use App\Models\Post;
use Illuminate\Console\Command;

/**
 * Rebuild every post's cached `body_html` from its Markdown `body`.
 *
 * The cache is kept current automatically whenever a post is saved (see
 * Post::booted). Run this only when the Markdown PIPELINE itself changes —
 * e.g. a heading-shift tweak in App\Support\Markdown or a CommonMark upgrade —
 * so already-stored posts pick up the new output. That is the entire
 * invalidation story: no TTLs, no cache keys.
 */
class RerenderPosts extends Command
{
    protected $signature = 'posts:rerender';

    protected $description = 'Rebuild every post\'s cached HTML from its Markdown source';

    public function handle(): int
    {
        $count = 0;

        // A re-render is mechanical, not an edit, so it must not bump
        // updated_at (the Atom feed's <updated> reads it). saveQuietly skips
        // the saving hook — renderBodyHtml() has already set the value.
        Post::withoutTimestamps(function () use (&$count) {
            Post::query()->chunkById(100, function ($posts) use (&$count) {
                foreach ($posts as $post) {
                    $post->renderBodyHtml();
                    $post->saveQuietly();
                    $count++;
                }
            });
        });

        $this->info("Re-rendered {$count} ".str('post')->plural($count).'.');

        return self::SUCCESS;
    }
}
