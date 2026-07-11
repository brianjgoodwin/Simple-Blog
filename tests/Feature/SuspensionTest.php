<?php

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function suspendableAuthor(): User
{
    $user = User::factory()->create(['username' => 'casey']);
    $user->pages()->create(['slug' => 'about', 'body' => 'About casey']);
    $user->pages()->create(['slug' => 'links', 'body' => '- a link']);
    Post::factory()->for($user)->published()->create([
        'title' => 'A Public Post',
        'slug' => 'a-public-post',
    ]);

    return $user;
}

// --- The artisan commands -----------------------------------------------------

test('author:suspend suspends and author:unsuspend reinstates', function () {
    $author = suspendableAuthor();

    $this->artisan('author:suspend', ['username' => 'casey'])
        ->expectsOutputToContain("Author 'casey' suspended")
        ->assertSuccessful();
    expect($author->fresh()->isSuspended())->toBeTrue();

    $this->artisan('author:unsuspend', ['username' => 'casey'])
        ->expectsOutputToContain("Author 'casey' reinstated")
        ->assertSuccessful();
    expect($author->fresh()->isSuspended())->toBeFalse();
});

test('both commands fail cleanly for an unknown username', function () {
    $this->artisan('author:suspend', ['username' => 'nobody'])->assertFailed();
    $this->artisan('author:unsuspend', ['username' => 'nobody'])->assertFailed();
});

test('suspending twice is harmless and keeps the original timestamp', function () {
    $author = suspendableAuthor();

    $this->artisan('author:suspend', ['username' => 'casey'])->assertSuccessful();
    $firstSuspendedAt = $author->fresh()->suspended_at;

    $this->travel(1)->hours();
    $this->artisan('author:suspend', ['username' => 'casey'])
        ->expectsOutputToContain('already suspended')
        ->assertSuccessful();

    expect($author->fresh()->suspended_at)->toEqual($firstSuspendedAt);
});

// --- The public surface disappears ---------------------------------------------

test('a suspended author\'s blog 404s everywhere public', function () {
    $author = suspendableAuthor();
    $author->suspended_at = now(); $author->save(); // direct: suspended_at is deliberately not fillable

    $this->get('/@casey')->assertNotFound();
    $this->get('/@casey/a-public-post')->assertNotFound();
    $this->get('/@casey/about')->assertNotFound();
    $this->get('/@casey/links')->assertNotFound();
});

test('unsuspending restores the blog exactly as it was', function () {
    $author = suspendableAuthor();
    $author->suspended_at = now(); $author->save(); // direct: suspended_at is deliberately not fillable
    $this->get('/@casey')->assertNotFound();

    $author->suspended_at = null; $author->save();

    $this->get('/@casey')->assertOk()->assertSee('A Public Post');
    $this->get('/@casey/a-public-post')->assertOk();
});

// --- Login and existing sessions ------------------------------------------------

test('a suspended author cannot log in, with the same error as a wrong password', function () {
    $author = suspendableAuthor();
    $author->suspended_at = now(); $author->save(); // direct: suspended_at is deliberately not fillable

    $response = $this->post('/login', [
        'email' => $author->email,
        'password' => 'password', // the factory's correct password
    ]);

    $response->assertSessionHasErrors(['email' => trans('auth.failed')]);
    $this->assertGuest();
});

test('an author suspended mid-session is logged out on their next request', function () {
    $author = suspendableAuthor();
    $this->actingAs($author);

    $author->suspended_at = now(); $author->save(); // direct: suspended_at is deliberately not fillable

    $this->get('/dashboard')->assertRedirect(route('login'));
    $this->assertGuest();
});

test('an unsuspended author can still log in and use the dashboard', function () {
    $author = suspendableAuthor();

    $this->post('/login', [
        'email' => $author->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard'));

    $this->get('/dashboard')->assertOk();
});
