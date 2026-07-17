<?php

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// --- Editing ----------------------------------------------------------------

test('an author can set a blog description', function () {
    $author = User::factory()->create(['username' => 'quinn']);

    $this->actingAs($author)
        ->put('/dashboard/appearance', [
            'theme' => 'default',
            'font' => 'sans',
            'description' => 'Notes on tea and typography.',
        ])
        ->assertRedirect(route('appearance.edit'));

    expect($author->refresh()->description)->toBe('Notes on tea and typography.');
});

test('a blank description is stored as null, not an empty string', function () {
    $author = User::factory()->create(['username' => 'quinn', 'description' => 'old tagline']);

    $this->actingAs($author)
        ->put('/dashboard/appearance', ['theme' => 'default', 'font' => 'sans', 'description' => '   ']);

    expect($author->refresh()->description)->toBeNull();
});

test('a description over 200 characters is rejected', function () {
    $author = User::factory()->create(['username' => 'quinn']);

    $this->actingAs($author)
        ->put('/dashboard/appearance', [
            'theme' => 'default',
            'font' => 'sans',
            'description' => str_repeat('x', 201),
        ])
        ->assertSessionHasErrors('description');
});

test('the description cannot be set by mass assignment', function () {
    $author = User::factory()->create(['username' => 'quinn']);

    // Same doctrine as theme/font/suspended_at: not in the fillable allowlist.
    $author->update(['description' => 'sneaky']);

    expect($author->refresh()->description)->toBeNull();
});

// --- Public surface ---------------------------------------------------------

test('the blog home shows the description and its meta tags when set', function () {
    User::factory()->create(['username' => 'quinn', 'description' => 'Tea and type.']);

    $this->get('/@quinn')
        ->assertOk()
        ->assertSee('Tea and type.')
        ->assertSee('<meta name="description" content="Tea and type.">', escape: false)
        ->assertSee('<meta property="og:description" content="Tea and type.">', escape: false);
});

test('the blog home omits the description meta when unset', function () {
    User::factory()->create(['username' => 'quinn']); // no description

    $this->get('/@quinn')
        ->assertOk()
        ->assertDontSee('<meta name="description"', escape: false);
});

test('the feed carries the description as a subtitle when set', function () {
    $author = User::factory()->create(['username' => 'quinn', 'description' => 'Tea and type.']);
    Post::factory()->for($author)->published()->create();

    $this->get('/@quinn/feed')
        ->assertOk()
        ->assertSee('<subtitle>Tea and type.</subtitle>', escape: false);
});

test('the feed omits the subtitle when there is no description', function () {
    $author = User::factory()->create(['username' => 'quinn']);
    Post::factory()->for($author)->published()->create();

    $this->get('/@quinn/feed')
        ->assertOk()
        ->assertDontSee('<subtitle>', escape: false);
});
