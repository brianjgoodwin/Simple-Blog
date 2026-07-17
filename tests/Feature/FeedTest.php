<?php

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the feed serves an author\'s published posts as Atom', function () {
    $author = User::factory()->create(['username' => 'brian', 'name' => 'Brian G']);
    Post::factory()->for($author)->published()->create([
        'title' => 'Hello World',
        'slug' => 'hello-world',
        'body' => '# A heading',
    ]);

    $response = $this->get('/@brian/feed');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/atom+xml');

    $response
        ->assertSee('<feed xmlns="http://www.w3.org/2005/Atom">', escape: false)
        ->assertSee('<title>Hello World</title>', escape: false)
        // Entry id is the permanent permalink (slugs freeze at first publish).
        ->assertSee('<id>'.route('blog.post', [$author, 'hello-world']).'</id>', escape: false)
        // Body is carried as entity-encoded HTML inside <content type="html">.
        ->assertSee('&lt;h2&gt;A heading&lt;/h2&gt;', escape: false);
});

test('the feed never includes drafts (the guarantee)', function () {
    $author = User::factory()->create(['username' => 'brian']);
    Post::factory()->for($author)->published()->create(['title' => 'Public One']);
    Post::factory()->for($author)->create(['title' => 'Secret Draft']); // draft by default

    $this->get('/@brian/feed')
        ->assertOk()
        ->assertSee('Public One')
        ->assertDontSee('Secret Draft');
});

test('the feed caps at 20 entries', function () {
    $author = User::factory()->create(['username' => 'brian']);
    Post::factory()->for($author)->published()->count(25)->create();

    $body = $this->get('/@brian/feed')->assertOk()->getContent();

    expect(substr_count($body, '<entry>'))->toBe(20);
});

test('a suspended author\'s feed is a 404', function () {
    $author = User::factory()->create(['username' => 'brian', 'suspended_at' => now()]);
    Post::factory()->for($author)->published()->create();

    $this->get('/@brian/feed')->assertNotFound();
});

test('an unknown author\'s feed is a 404', function () {
    $this->get('/@nobody/feed')->assertNotFound();
});

test('the feed answers If-Modified-Since with a 304', function () {
    $author = User::factory()->create(['username' => 'brian']);
    Post::factory()->for($author)->published()->create();

    $lastModified = $this->get('/@brian/feed')->assertOk()->headers->get('Last-Modified');
    expect($lastModified)->not->toBeNull();

    // A conditional re-poll with that timestamp gets a bare 304.
    $this->get('/@brian/feed', ['If-Modified-Since' => $lastModified])
        ->assertStatus(304)
        ->assertNoContent(304);
});

test('public pages advertise the feed for autodiscovery', function () {
    $author = User::factory()->create(['username' => 'brian', 'name' => 'Brian G']);
    Post::factory()->for($author)->published()->create(['slug' => 'p1']);

    $feedUrl = route('blog.feed', $author);

    $this->get('/@brian')
        ->assertOk()
        ->assertSee('type="application/atom+xml"', escape: false)
        ->assertSee($feedUrl, escape: false);

    $this->get('/@brian/p1')
        ->assertOk()
        ->assertSee('type="application/atom+xml"', escape: false);
});
