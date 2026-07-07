<?php

namespace App\Support;

use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Renders user-authored Markdown to HTML for public display.
 *
 * SECURITY: this is the app's main XSS surface. Post/page bodies are written
 * by authors but shown to the public, so the output must never contain
 * author-supplied executable HTML.
 *
 *   - html_input => 'strip'      : raw HTML in the source is removed, so
 *                                  <script>, <iframe>, onerror=, etc. cannot
 *                                  reach the page.
 *   - allow_unsafe_links => false: neutralizes javascript:, data:, vbscript:
 *                                  URLs in links/images.
 *
 * All public rendering MUST go through here — never echo a raw body.
 */
class Markdown
{
    public static function toHtml(?string $markdown): HtmlString
    {
        return new HtmlString(Str::markdown($markdown ?? '', [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]));
    }
}
