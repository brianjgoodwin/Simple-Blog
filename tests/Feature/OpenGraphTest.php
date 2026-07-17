<?php

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a post page emits Open Graph article tags with a description excerpt', function () {
    $author = User::factory()->create(['username' => 'brian', 'name' => 'Brian G']);
    Post::factory()->for($author)->published()->create([
        'title' => 'My Post',
        'slug' => 'my-post',
        'body' => 'The quick brown fox jumps over the lazy dog.',
    ]);

    $this->get('/@brian/my-post')
        ->assertOk()
        ->assertSee('<meta property="og:type" content="article">', escape: false)
        ->assertSee('<meta property="og:title" content="My Post">', escape: false)
        ->assertSee('property="article:published_time"', escape: false)
        ->assertSee('<meta name="description" content="The quick brown fox jumps over the lazy dog.">', escape: false);
});

test('the excerpt is plain text drawn from the rendered body, not raw Markdown', function () {
    $author = User::factory()->create(['username' => 'brian']);
    $post = Post::factory()->for($author)->published()->create([
        'body' => "# Big Heading\n\nSome **bold** words here.",
    ]);

    expect($post->excerpt())->toBe('Big Heading Some bold words here.');
});

test('the home page uses the website Open Graph type', function () {
    $author = User::factory()->create(['username' => 'brian']);

    $this->get('/@brian')
        ->assertOk()
        ->assertSee('<meta property="og:type" content="website">', escape: false)
        ->assertDontSee('property="article:published_time"', escape: false);
});
