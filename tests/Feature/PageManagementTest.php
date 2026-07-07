<?php

use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Create an author with their two seeded pages, the way author:create does.
 */
function authorWithPages(): User
{
    $user = User::factory()->create();
    $user->pages()->create(['slug' => 'about', 'body' => '']);
    $user->pages()->create(['slug' => 'links', 'body' => '']);

    return $user;
}

// --- Access control ---------------------------------------------------------

test('guests are redirected from the page editor', function () {
    $this->get(route('pages.edit', 'about'))->assertRedirect(route('login'));
});

// --- Editing your own pages -------------------------------------------------

test('an author can view the edit form for their About page', function () {
    $author = authorWithPages();

    $this->actingAs($author)
        ->get(route('pages.edit', 'about'))
        ->assertOk()
        ->assertSee('About');
});

test('an author can update their About page', function () {
    $author = authorWithPages();

    $this->actingAs($author)
        ->put(route('pages.update', 'about'), ['body' => '# Hello, I am Brian'])
        ->assertRedirect(route('pages.edit', 'about'));

    $about = $author->pages()->where('slug', 'about')->first();
    expect($about->body)->toBe('# Hello, I am Brian');
});

test('an author can update their Links page', function () {
    $author = authorWithPages();

    $this->actingAs($author)
        ->put(route('pages.update', 'links'), ['body' => '- [Site](https://example.com)'])
        ->assertRedirect(route('pages.edit', 'links'));

    expect($author->pages()->where('slug', 'links')->first()->body)
        ->toBe('- [Site](https://example.com)');
});

// --- Invalid slugs 404 ------------------------------------------------------

test('an unknown page slug returns 404', function () {
    $author = authorWithPages();

    $this->actingAs($author)
        ->get(route('pages.edit', 'contact')) // not in the editable set
        ->assertNotFound();
});

// --- The multi-tenancy guarantee for pages ----------------------------------

test('an author only ever edits their OWN page, never another author\'s', function () {
    $me = authorWithPages();
    $other = authorWithPages();

    // Give the other author distinctive About content.
    $other->pages()->where('slug', 'about')->update(['body' => 'OTHER PERSON CONTENT']);

    // I edit "about" — it must hit MY row, leaving the other author's untouched.
    $this->actingAs($me)
        ->put(route('pages.update', 'about'), ['body' => 'MY CONTENT'])
        ->assertRedirect();

    expect($me->pages()->where('slug', 'about')->first()->body)->toBe('MY CONTENT');
    expect($other->pages()->where('slug', 'about')->first()->body)->toBe('OTHER PERSON CONTENT');
});

test('editing pages works even before content exists (empty body allowed)', function () {
    $author = authorWithPages();

    $this->actingAs($author)
        ->put(route('pages.update', 'about'), ['body' => ''])
        ->assertRedirect(route('pages.edit', 'about'));

    expect($author->pages()->where('slug', 'about')->first()->body)->toBe('');
});
