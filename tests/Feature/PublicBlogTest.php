<?php

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function author(string $username = 'brian'): User
{
    $user = User::factory()->create(['username' => $username, 'name' => 'Brian G']);
    $user->pages()->create(['slug' => 'about', 'body' => '']);
    $user->pages()->create(['slug' => 'links', 'body' => '']);

    return $user;
}

// --- Blog home --------------------------------------------------------------

test('the blog home lists an author\'s published posts', function () {
    $author = author();
    Post::factory()->for($author)->published()->create(['title' => 'Hello World']);

    $this->get('/@brian')
        ->assertOk()
        ->assertSee('Hello World')
        ->assertSee('Brian G');
});

test('the blog home does not show drafts', function () {
    $author = author();
    Post::factory()->for($author)->create(['title' => 'Secret Draft']); // draft by default

    $this->get('/@brian')
        ->assertOk()
        ->assertDontSee('Secret Draft');
});

test('an author with no published posts still gets a blog home with an empty state', function () {
    author();

    $this->get('/@brian')
        ->assertOk()
        ->assertSee('No posts yet');
});

test('an unknown username returns 404', function () {
    $this->get('/@nobody')->assertNotFound();
});

// --- Single post ------------------------------------------------------------

test('a published post is publicly readable', function () {
    $author = author();
    $post = Post::factory()->for($author)->published()->create([
        'title' => 'My Post',
        'slug' => 'my-post',
        'body' => '# A heading',
    ]);

    $this->get('/@brian/my-post')
        ->assertOk()
        ->assertSee('My Post')
        ->assertSee('A heading');
});

test('a draft post is not publicly reachable (404, not 403)', function () {
    $author = author();
    Post::factory()->for($author)->create([
        'title' => 'Draft Post',
        'slug' => 'draft-post',
    ]); // draft

    $this->get('/@brian/draft-post')->assertNotFound();
});

test('an unknown slug returns 404', function () {
    author();
    $this->get('/@brian/does-not-exist')->assertNotFound();
});

test('one author\'s post is not reachable under another author\'s username', function () {
    $a = author('alice');
    $b = author('bob');
    Post::factory()->for($a)->published()->create(['slug' => 'alice-post']);

    // Bob has no such post; the slug belongs to Alice.
    $this->get('/@bob/alice-post')->assertNotFound();
    // But it IS reachable under Alice.
    $this->get('/@alice/alice-post')->assertOk();
});

// --- Pages ------------------------------------------------------------------

test('the About and Links pages render publicly', function () {
    $author = author();
    $author->pages()->where('slug', 'about')->update(['body' => '# About me']);
    $author->pages()->where('slug', 'links')->update(['body' => '- a link']);

    $this->get('/@brian/about')->assertOk()->assertSee('About me');
    $this->get('/@brian/links')->assertOk()->assertSee('a link');
});

// --- The XSS guarantee (the important one) ----------------------------------

test('a script tag in a post body is stripped from the public page', function () {
    $author = author();
    Post::factory()->for($author)->published()->create([
        'slug' => 'xss-test',
        'body' => "Safe text\n\n<script>alert('pwned')</script>",
    ]);

    $response = $this->get('/@brian/xss-test');

    $response->assertOk()
        ->assertSee('Safe text')
        ->assertDontSee('<script>', escape: false)  // the tag is gone
        ->assertDontSee('alert(', escape: false);    // and its contents
});

test('a javascript: link in a post body is neutralized', function () {
    $author = author();
    Post::factory()->for($author)->published()->create([
        'slug' => 'js-link',
        'body' => '[click me](javascript:alert(1))',
    ]);

    $response = $this->get('/@brian/js-link');

    $response->assertOk()
        ->assertSee('click me')                          // link text remains
        ->assertDontSee('javascript:alert', escape: false); // but href is stripped
});

test('an onerror image payload is stripped', function () {
    $author = author();
    Post::factory()->for($author)->published()->create([
        'slug' => 'img-xss',
        'body' => '<img src=x onerror=alert(1)>',
    ]);

    $this->get('/@brian/img-xss')
        ->assertOk()
        ->assertDontSee('onerror', escape: false);
});
