<?php

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('search matches the post title', function () {
    $author = User::factory()->create(['username' => 'brian']);
    Post::factory()->for($author)->published()->create(['title' => 'The Blue Door']);

    $this->get('/@brian/search?q=blue')->assertOk()->assertSee('The Blue Door');
});

test('search matches the post body', function () {
    $author = User::factory()->create(['username' => 'brian']);
    Post::factory()->for($author)->published()->create([
        'title' => 'Untitled', 'body' => 'a paragraph about aardvarks',
    ]);

    $this->get('/@brian/search?q=aardvark')->assertOk()->assertSee('Untitled');
});

test('search is case-insensitive', function () {
    $author = User::factory()->create(['username' => 'brian']);
    Post::factory()->for($author)->published()->create(['title' => 'Hello World']);

    $this->get('/@brian/search?q=hello')->assertOk()->assertSee('Hello World');
});

test('search never returns drafts', function () {
    $author = User::factory()->create(['username' => 'brian']);
    Post::factory()->for($author)->published()->create(['title' => 'findable widget']);
    Post::factory()->for($author)->create(['title' => 'secret widget']); // draft by default

    $this->get('/@brian/search?q=widget')
        ->assertOk()
        ->assertSee('findable widget')
        ->assertDontSee('secret widget');
});

test('search only covers the given author\'s posts', function () {
    $alice = User::factory()->create(['username' => 'alice']);
    $bob = User::factory()->create(['username' => 'bob']);
    Post::factory()->for($alice)->published()->create(['title' => 'alice unicorn']);
    Post::factory()->for($bob)->published()->create(['title' => 'bob unicorn']);

    $this->get('/@alice/search?q=unicorn')
        ->assertOk()
        ->assertSee('alice unicorn')
        ->assertDontSee('bob unicorn');
});

test('a LIKE percent wildcard in the query is matched literally', function () {
    $author = User::factory()->create(['username' => 'brian']);
    Post::factory()->for($author)->published()->create(['title' => '100% cotton shirt']);
    Post::factory()->for($author)->published()->create(['title' => '100 recipes']);

    // Without escaping, "100%" would wildcard-match "100 recipes" too.
    $this->get('/@brian/search?q='.urlencode('100%'))
        ->assertOk()
        ->assertSee('100% cotton shirt')
        ->assertDontSee('100 recipes');
});

test('a LIKE underscore wildcard in the query is matched literally', function () {
    $author = User::factory()->create(['username' => 'brian']);
    Post::factory()->for($author)->published()->create(['title' => 'draft_final notes']);
    Post::factory()->for($author)->published()->create(['title' => 'draftxfinal notes']);

    $this->get('/@brian/search?q='.urlencode('draft_final'))
        ->assertOk()
        ->assertSee('draft_final notes')
        ->assertDontSee('draftxfinal notes');
});

test('an empty query shows a prompt, not every post', function () {
    $author = User::factory()->create(['username' => 'brian']);
    Post::factory()->for($author)->published()->create(['title' => 'Should Not List']);

    $this->get('/@brian/search')
        ->assertOk()
        ->assertSee('Type a word or phrase')
        ->assertDontSee('Should Not List');
});

test('a query with no matches reports it', function () {
    $author = User::factory()->create(['username' => 'brian']);
    Post::factory()->for($author)->published()->create(['title' => 'Apples']);

    $this->get('/@brian/search?q=zebra')->assertOk()->assertSee('No posts match');
});

test('a suspended author has no search', function () {
    User::factory()->create(['username' => 'brian', 'suspended_at' => now()]);

    $this->get('/@brian/search?q=anything')->assertNotFound();
});

test('the blog home shows a search box', function () {
    $author = User::factory()->create(['username' => 'brian']);

    $this->get('/@brian')
        ->assertOk()
        ->assertSee(route('blog.search', $author), escape: false)
        ->assertSee('role="search"', escape: false);
});
