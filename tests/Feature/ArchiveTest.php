<?php

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the archive lists published posts grouped by year, newest first', function () {
    $author = User::factory()->create(['username' => 'brian']);
    Post::factory()->for($author)->published()->create([
        'title' => 'Old Post', 'published_at' => now()->setDate(2024, 1, 1),
    ]);
    Post::factory()->for($author)->published()->create([
        'title' => 'New Post', 'published_at' => now()->setDate(2026, 5, 1),
    ]);

    $this->get('/@brian/archive')
        ->assertOk()
        ->assertSee('Archive')
        ->assertSee('2026')
        ->assertSee('2024')
        ->assertSeeInOrder(['New Post', 'Old Post']); // newest year's posts first
});

test('the archive never shows drafts', function () {
    $author = User::factory()->create(['username' => 'brian']);
    Post::factory()->for($author)->published()->create(['title' => 'Shipped']);
    Post::factory()->for($author)->create(['title' => 'Secret Draft']); // draft by default

    $this->get('/@brian/archive')
        ->assertOk()
        ->assertSee('Shipped')
        ->assertDontSee('Secret Draft');
});

test('an empty archive shows an empty state', function () {
    User::factory()->create(['username' => 'brian']);

    $this->get('/@brian/archive')->assertOk()->assertSee('No posts yet');
});

test('a suspended author has no archive', function () {
    User::factory()->create(['username' => 'brian', 'suspended_at' => now()]);

    $this->get('/@brian/archive')->assertNotFound();
});

test('an unknown author has no archive', function () {
    $this->get('/@nobody/archive')->assertNotFound();
});

test('the blog nav links to the archive', function () {
    $author = User::factory()->create(['username' => 'brian']);

    $this->get('/@brian')
        ->assertOk()
        ->assertSee(route('blog.archive', $author), escape: false);
});
