<?php

use App\Support\Markdown;

// --- Heading shift (a11y: a body must never emit an <h1>) --------------------

test('author headings are shifted down one level', function () {
    expect((string) Markdown::toHtml("# Top\n\n## Second"))
        ->toContain('<h2>Top</h2>')
        ->toContain('<h3>Second</h3>')
        ->not->toContain('<h1>');
});

test('the heading shift caps at h6', function () {
    expect((string) Markdown::toHtml("###### Deep\n\n##### Five"))
        ->toContain('<h6>Deep</h6>')
        ->toContain('<h6>Five</h6>')
        ->not->toContain('<h7>');
});

// --- The security posture must survive the custom converter ------------------

test('raw HTML is still stripped', function () {
    expect((string) Markdown::toHtml('Hello <script>alert(1)</script>'))
        ->not->toContain('<script>');
});

test('unsafe links are still neutralized', function () {
    expect((string) Markdown::toHtml('[x](javascript:alert(1))'))
        ->not->toContain('javascript:');
});

test('GitHub-flavored markdown still works', function () {
    expect((string) Markdown::toHtml("~~gone~~\n\n| a | b |\n|---|---|\n| 1 | 2 |"))
        ->toContain('<del>gone</del>')
        ->toContain('<table>');
});
