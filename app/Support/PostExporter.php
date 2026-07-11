<?php

namespace App\Support;

use App\Models\Post;
use App\Models\User;
use RuntimeException;
use ZipArchive;

/**
 * Builds an author's full export: a zip of plain Markdown files.
 *
 * This is the "your words leave with you" feature (PLAN.md Phase 13). The
 * export is deliberately app-agnostic: one .md file per post with minimal
 * YAML front-matter, plus the About/Links pages. No HTML — the Markdown is
 * the canonical content; rendered HTML is our presentation, not theirs.
 *
 * Layout inside the zip:
 *   posts/{slug}.md   — every post, drafts included (they're the author's
 *                       words too). Post slugs are unique per author
 *                       (SlugGenerator), so filenames can't collide.
 *   about.md          — pages sit at the root; posts sit in posts/ so a
 *   links.md            post slugged "about" can't collide with a page.
 *
 * SECURITY: the caller passes the authenticated user; nothing here reads
 * request input, so there is no way to reach another author's content.
 */
class PostExporter
{
    /**
     * Build the zip on disk and return its path.
     *
     * The file lands in the system temp dir; the controller serves it with
     * deleteFileAfterSend so it does not outlive the download. tempnam()
     * creates the file with 0600 permissions, which matters because the
     * export contains unpublished drafts.
     */
    public function export(User $author): string
    {
        $path = tempnam(sys_get_temp_dir(), 'blog-export-');

        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file for the export.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not open the export zip for writing.');
        }

        foreach ($author->posts()->orderBy('created_at')->get() as $post) {
            $zip->addFromString('posts/'.$post->slug.'.md', $this->postFile($post));
        }

        foreach ($author->pages()->orderBy('slug')->get() as $page) {
            $zip->addFromString($page->slug.'.md', $page->body ?? '');
        }

        $zip->close();

        return $path;
    }

    /**
     * One post as Markdown with minimal YAML front-matter.
     *
     * The title is JSON-encoded: a JSON string is a valid YAML double-quoted
     * scalar, so quotes, colons, and unicode in titles survive without us
     * hand-rolling YAML escaping. The other values (slug, status, ISO dates)
     * come from constrained charsets and are written bare for readability.
     */
    private function postFile(Post $post): string
    {
        $lines = [
            '---',
            'title: '.json_encode($post->title, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'slug: '.$post->slug,
            'status: '.$post->status->value,
            'published_at: '.($post->published_at?->toIso8601String() ?? 'null'),
            'created_at: '.$post->created_at->toIso8601String(),
            'updated_at: '.$post->updated_at->toIso8601String(),
            '---',
            '',
        ];

        return implode("\n", $lines).($post->body ?? '')."\n";
    }
}
