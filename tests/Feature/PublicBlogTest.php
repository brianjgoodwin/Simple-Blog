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

test('the site footer appears on public blogs and the landing page', function () {
    author();

    // The heart is wrapped in aria-hidden/sr-only spans, so assert the
    // pieces in order rather than the contiguous string.
    $this->get('/@brian')->assertSeeInOrder(['Made with', '♥', 'by Brian']);
    $this->get('/')->assertSeeInOrder(['Made with', '♥', 'by Brian']);
});

// --- Full previews + pagination ---------------------------------------------

test('the blog home shows the full rendered body of each post', function () {
    $author = author();
    Post::factory()->for($author)->published()->create([
        'title' => 'Full Body Post',
        'body' => '# A Heading'."\n\n".'Some **bold** content in the river.',
    ]);

    $this->get('/@brian')
        ->assertOk()
        ->assertSee('A Heading')                              // heading rendered
        ->assertSee('<strong>bold</strong>', escape: false)   // markdown -> HTML inline
        ->assertSee('content in the river');
});

test('the blog home paginates at 5 posts per page', function () {
    $author = author();
    // 6 published posts -> 5 on page 1, 1 on page 2.
    Post::factory()->for($author)->published()->count(6)->create();

    // Page 1 shows 5, and a pagination control to page 2.
    $page1 = $this->get('/@brian')->assertOk();
    expect($page1->viewData('posts')->count())->toBe(5);
    expect($page1->viewData('posts')->hasPages())->toBeTrue();

    // Page 2 shows the remaining 1.
    $page2 = $this->get('/@brian?page=2')->assertOk();
    expect($page2->viewData('posts')->count())->toBe(1);
});

test('disabled pagination controls are hidden from AT, not given a prohibited aria-label', function () {
    $author = author();
    Post::factory()->for($author)->published()->count(6)->create(); // 2 pages at 5/page

    // Page 1: "Previous" is disabled — no aria-label (it is prohibited on the
    // generic <span>); the active "Next" link still carries its label on an <a>.
    $this->get('/@brian')
        ->assertOk()
        ->assertDontSee('aria-label="Previous"', escape: false)
        ->assertSee('aria-label="Next"', escape: false);

    // Last page: "Next" is disabled; the active "Previous" link is labelled.
    $this->get('/@brian?page=2')
        ->assertOk()
        ->assertDontSee('aria-label="Next"', escape: false)
        ->assertSee('aria-label="Previous"', escape: false);
});

test('pagination only counts published posts', function () {
    $author = author();
    Post::factory()->for($author)->published()->count(3)->create();
    Post::factory()->for($author)->count(20)->create(); // drafts — must not paginate in

    $page = $this->get('/@brian')->assertOk();
    expect($page->viewData('posts')->total())->toBe(3);   // only the 3 published
    expect($page->viewData('posts')->hasPages())->toBeFalse();
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

test('a post page answers If-Modified-Since with a 304', function () {
    $author = author();
    Post::factory()->for($author)->published()->create(['slug' => 'cached-post']);

    $lastModified = $this->get('/@brian/cached-post')->assertOk()->headers->get('Last-Modified');
    expect($lastModified)->not->toBeNull();

    $this->get('/@brian/cached-post', ['If-Modified-Since' => $lastModified])
        ->assertStatus(304);
});

test('editing a post or the author invalidates the post page 304', function () {
    $author = author();
    $post = Post::factory()->for($author)->published()->create(['slug' => 'cached-post']);

    $lastModified = $this->get('/@brian/cached-post')->headers->get('Last-Modified');

    // An edit re-stamps the post (HTTP dates are 1s resolution, so travel).
    $this->travel(1)->minutes();
    $post->update(['body' => 'revised']);

    $this->get('/@brian/cached-post', ['If-Modified-Since' => $lastModified])
        ->assertOk()
        ->assertSee('revised');

    // A change to the author (name, description, theme) alters the page
    // shell without touching the post — it must also invalidate. The 200
    // (rather than 304) is the invalidation; the name renders in the shell.
    $lastModified = $this->get('/@brian/cached-post')->headers->get('Last-Modified');
    $this->travel(1)->minutes();
    $author->name = 'Brian Renamed';
    $author->save();

    $this->get('/@brian/cached-post', ['If-Modified-Since' => $lastModified])
        ->assertOk()
        ->assertSee('Brian Renamed');
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

// --- Host landing page & 404s ----------------------------------------------

test('the root landing page invites an anonymous visitor to sign in', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Author sign in')
        ->assertDontSee('Go to your dashboard');
});

test('the root landing page points a logged-in author at the dashboard', function () {
    $this->actingAs(author());

    $this->get('/')
        ->assertOk()
        ->assertSee('Go to your dashboard')
        ->assertDontSee('Author sign in');
});

test('an unknown author returns a 404, not a 403', function () {
    $this->get('/@nobody')->assertNotFound();
});

test('an unknown slug for a real author returns a 404', function () {
    author();

    $this->get('/@brian/does-not-exist')->assertNotFound();
});

test('a draft slug is a 404 on the public post route, never a 403', function () {
    $author = author();
    Post::factory()->for($author)->create(['slug' => 'hidden-draft']); // draft by default

    $this->get('/@brian/hidden-draft')->assertNotFound();
});

// --- Heading hierarchy (a11y) -------------------------------------------------

test('the blog name is the h1 on the home page but not on a post page', function () {
    $author = User::factory()->create();
    $post = Post::factory()->for($author)->published()->create();

    // Home: blog name wrapped in <h1>.
    $this->get(route('blog.home', $author))
        ->assertSeeInOrder(['<h1', $author->name], escape: false);

    // Post page: the post title is the h1; the blog name is not a heading.
    $this->get(route('blog.post', [$author, $post->slug]))
        ->assertSeeInOrder(['<p class="text-2xl font-bold">', '<h1'], escape: false);
});
