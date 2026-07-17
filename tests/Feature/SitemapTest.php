<?php

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the sitemap lists published posts and the standard pages', function () {
    $author = User::factory()->create(['username' => 'brian']);
    Post::factory()->for($author)->published()->create(['slug' => 'shipped']);
    Post::factory()->for($author)->create(['slug' => 'hidden-draft']); // draft by default

    $response = $this->get('/@brian/sitemap.xml');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/xml');

    $response
        ->assertSee(route('blog.home', $author), escape: false)
        ->assertSee(route('blog.about', $author), escape: false)
        ->assertSee(route('blog.links', $author), escape: false)
        ->assertSee(route('blog.post', [$author, 'shipped']), escape: false)
        ->assertDontSee('hidden-draft'); // the guarantee, here too
});

test('a suspended author has no sitemap', function () {
    $author = User::factory()->create(['username' => 'brian', 'suspended_at' => now()]);

    $this->get('/@brian/sitemap.xml')->assertNotFound();
});

test('an unknown author has no sitemap', function () {
    $this->get('/@nobody/sitemap.xml')->assertNotFound();
});
