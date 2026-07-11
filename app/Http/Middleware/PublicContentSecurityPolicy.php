<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * A strict Content-Security-Policy for the PUBLIC pages.
 *
 * The Markdown pipeline already strips raw HTML and unsafe links, and tests
 * pin that — this header is the backstop for the day a parser CVE or a
 * future feature pokes a hole in it: even then, injected scripts will not
 * execute in readers' browsers.
 *
 * It can be this strict because the public surface is deliberately tiny:
 * one same-origin stylesheet, no JavaScript, no fonts, no third-party
 * anything. The authenticated dashboard is NOT covered — Alpine evaluates
 * expressions with eval-like calls and Breeze loads webfonts, so a useful
 * policy there is a separate, weaker exercise.
 *
 * img-src 'self' also means a Markdown ![image](https://elsewhere) never
 * loads for readers. That is deliberate, not collateral: content is
 * "Markdown only, no images" (PLAN.md locked decision), and remote images
 * would leak every reader's IP to a third-party host.
 *
 * Known gap, accepted: a 404 raised BEFORE this middleware runs (unknown
 * author in route-model binding, or a URL matching no route at all) is
 * served without the header. That 404 page renders no author content, so
 * there is nothing for a CSP to backstop there.
 */
class PublicContentSecurityPolicy
{
    private const POLICY = "default-src 'none'; style-src 'self'; img-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // In local dev, `npm run dev` serves assets from the Vite dev server
        // on another origin and injects its hot-reload script — a strict CSP
        // would blank the page. Built assets (every real deployment, and
        // local after `npm run build`) get the header.
        if (! Vite::isRunningHot()) {
            $response->headers->set('Content-Security-Policy', self::POLICY);
        }

        return $response;
    }
}
