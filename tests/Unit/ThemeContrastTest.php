<?php

use App\Enums\Theme;

/*
 * Codifies the rule stated in the Theme enum and app.css: every theme ships
 * WCAG AA. The colours live in app.css (the source of truth), so this parses
 * them straight from there and does the four ratio computations the doc calls
 * for. A new or edited theme that fails contrast now fails CI — "verify before
 * shipping" stops being a manual step.
 */

function wcagLuminance(string $hex): float
{
    $hex = ltrim($hex, '#');
    $channels = array_map(function (string $pair) {
        $c = hexdec($pair) / 255;

        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }, [substr($hex, 0, 2), substr($hex, 2, 2), substr($hex, 4, 2)]);

    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

function wcagContrast(string $a, string $b): float
{
    $la = wcagLuminance($a);
    $lb = wcagLuminance($b);

    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
}

/**
 * Parse app.css into [selector => [css-var => hex]], keeping only blocks that
 * actually define a theme (i.e. have --theme-bg).
 *
 * @return array<string, array<string, string>>
 */
function wcagThemeBlocks(): array
{
    $css = file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');
    preg_match_all('/(:root|\[data-theme="[^"]+"\])\s*\{(.*?)\}/s', $css, $matches, PREG_SET_ORDER);

    $blocks = [];
    foreach ($matches as [, $selector, $body]) {
        preg_match_all('/--(theme-[a-z]+):\s*(#[0-9a-fA-F]{6})/', $body, $vars, PREG_SET_ORDER);
        $vals = [];
        foreach ($vars as [, $name, $hex]) {
            $vals[$name] = $hex;
        }
        if (isset($vals['theme-bg'])) {
            $blocks[$selector] = $vals;
        }
    }

    return $blocks;
}

test('every theme meets WCAG AA for accent, muted, and body text', function () {
    $body = '#111827'; // gray-900 — the post body colour on every theme
    $blocks = wcagThemeBlocks();

    expect($blocks)->not->toBeEmpty();

    foreach ($blocks as $selector => $v) {
        $bg = $v['theme-bg'];
        $this->assertGreaterThanOrEqual(4.5, wcagContrast($v['theme-accent'], $bg), "$selector: accent vs bg");
        $this->assertGreaterThanOrEqual(4.5, wcagContrast($v['theme-muted'], $bg), "$selector: muted vs bg");
        $this->assertGreaterThanOrEqual(4.5, wcagContrast($body, $bg), "$selector: body vs bg");
    }
});

test('every Theme case has a CSS block whose bg and accent match its swatch', function () {
    $blocks = wcagThemeBlocks();

    foreach (Theme::cases() as $theme) {
        // The default theme lives in :root; the rest in [data-theme="…"].
        $selector = $theme === Theme::Default ? ':root' : '[data-theme="'.$theme->value.'"]';

        $this->assertArrayHasKey($selector, $blocks, "no CSS block for {$theme->value}");

        $swatch = $theme->swatch();
        $this->assertSame($swatch['bg'], $blocks[$selector]['theme-bg'], "{$theme->value} bg out of sync with app.css");
        $this->assertSame($swatch['accent'], $blocks[$selector]['theme-accent'], "{$theme->value} accent out of sync with app.css");
    }
});
