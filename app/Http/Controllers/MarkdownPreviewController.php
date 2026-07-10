<?php

namespace App\Http\Controllers;

use App\Support\Markdown;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Renders a Markdown preview for the composer.
 *
 * Deliberately goes through App\Support\Markdown — the exact pipeline the
 * public pages use — so what the author previews is byte-for-byte what will
 * publish, including the HTML stripping and unsafe-link handling. Never add
 * a second, "friendlier" renderer here: a preview that differs from the
 * published output is worse than no preview.
 *
 * Auth-only (routed inside the dashboard group). The response is an HTML
 * fragment the composer injects into the preview pane.
 */
class MarkdownPreviewController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'body' => ['nullable', 'string'],
        ]);

        return response(Markdown::toHtml($validated['body'] ?? ''));
    }
}
