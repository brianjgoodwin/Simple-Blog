<?php

use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// The cache is a derived read-copy of `body`; these pin that it stays in sync
// through every write path, matches the public pipeline, and that the manual
// rebuild command is mechanical (no updated_at bump).

test('saving a post caches its rendered HTML', function () {
    $post = Post::factory()->create(['body' => '# Hello']);

    // Same pipeline as the public pages: author headings shift down (# -> h2).
    expect($post->getRawOriginal('body_html'))->toContain('<h2>Hello</h2>');
    expect((string) $post->body_html)->toContain('<h2>Hello</h2>');
});

test('the cached HTML is exposed as a safe HtmlString', function () {
    $post = Post::factory()->create(['body' => 'plain']);

    expect($post->body_html)->toBeInstanceOf(Illuminate\Support\HtmlString::class);
});

test('editing the body updates the cached HTML', function () {
    $post = Post::factory()->create(['body' => 'first']);
    expect((string) $post->body_html)->toContain('first');

    $post->update(['body' => 'second']);

    expect((string) $post->fresh()->body_html)
        ->toContain('second')
        ->not->toContain('first');
});

test('the cached HTML strips unsafe author HTML', function () {
    $post = Post::factory()->create([
        'body' => "Safe text\n\n<script>alert('x')</script>",
    ]);

    expect((string) $post->body_html)
        ->toContain('Safe text')
        ->not->toContain('<script>');
});

test('a save that does not touch the body leaves the cache intact', function () {
    $post = Post::factory()->create(['body' => 'keep me']);
    $original = $post->getRawOriginal('body_html');

    // A title-only edit (publish/unpublish are the same shape: body untouched).
    $post->update(['title' => 'A New Title']);

    expect($post->fresh()->getRawOriginal('body_html'))->toBe($original);
});

test('posts:rerender rebuilds the cache without bumping updated_at', function () {
    $post = Post::factory()->create(['body' => 'hello world']);

    // Simulate a stale/missing cache — e.g. a row written before this column,
    // or before a pipeline change. saveQuietly + withoutTimestamps so this
    // setup step itself changes nothing but body_html.
    Post::withoutTimestamps(fn () => $post->forceFill(['body_html' => null])->saveQuietly());
    $stampBefore = $post->fresh()->updated_at;

    $this->artisan('posts:rerender')
        ->expectsOutputToContain('Re-rendered 1 post.')
        ->assertSuccessful();

    $fresh = $post->fresh();
    expect((string) $fresh->body_html)->toContain('hello world');
    expect($fresh->updated_at->equalTo($stampBefore))->toBeTrue();
});
