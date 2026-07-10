<?php

namespace App\Support;

use Illuminate\Support\HtmlString;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\GithubFlavoredMarkdownConverter;

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
 * ACCESSIBILITY: author headings are shifted down one level (# -> h2, capped
 * at h6) so a body can never emit an <h1> that competes with the page title
 * the template renders.
 *
 * All public rendering MUST go through here — never echo a raw body.
 */
class Markdown
{
    public static function toHtml(?string $markdown): HtmlString
    {
        // Same converter Str::markdown() uses, built directly so we can
        // hook the parsed document before rendering.
        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        $converter->getEnvironment()->addEventListener(
            DocumentParsedEvent::class,
            function (DocumentParsedEvent $event): void {
                foreach ($event->getDocument()->iterator() as $node) {
                    if ($node instanceof Heading) {
                        $node->setLevel(min($node->getLevel() + 1, 6));
                    }
                }
            }
        );

        return new HtmlString((string) $converter->convert($markdown ?? ''));
    }
}
