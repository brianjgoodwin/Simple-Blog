<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

/**
 * Helper: run author:create with sensible defaults, overriding as needed.
 */
function createAuthor(array $options = []): int
{
    return Artisan::call('author:create', array_merge([
        '--name' => 'Brian Goodwin',
        '--username' => 'brian',
        '--email' => 'brian@example.com',
        '--password' => 'supersecret123',
    ], $options));
}

test('it creates an author with a hashed password', function () {
    $exit = createAuthor();

    expect($exit)->toBe(0);

    $user = User::where('username', 'brian')->first();

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Brian Goodwin')
        ->and($user->email)->toBe('brian@example.com');

    // Password must be hashed, never stored in plaintext.
    expect($user->password)->not->toBe('supersecret123');
    expect(Hash::check('supersecret123', $user->password))->toBeTrue();
});

test('it seeds About and Links pages for the new author', function () {
    createAuthor();

    $user = User::where('username', 'brian')->first();

    expect($user->pages)->toHaveCount(2)
        ->and($user->pages->pluck('slug')->sort()->values()->all())
        ->toBe(['about', 'links']);
});

test('it rejects a duplicate username', function () {
    createAuthor(); // first 'brian'

    $exit = createAuthor([
        '--email' => 'other@example.com', // unique email, duplicate username
    ]);

    expect($exit)->toBe(1);
    expect(User::where('username', 'brian')->count())->toBe(1);
});

test('it rejects a duplicate email', function () {
    createAuthor(); // first brian@example.com

    $exit = createAuthor([
        '--username' => 'brian2', // unique username, duplicate email
    ]);

    expect($exit)->toBe(1);
    expect(User::count())->toBe(1);
});

test('it rejects a username with invalid characters', function () {
    $exit = createAuthor([
        '--username' => 'Brian Goodwin', // spaces + uppercase
    ]);

    expect($exit)->toBe(1);
    expect(User::count())->toBe(0);
});

test('it rejects a password shorter than 8 characters', function () {
    $exit = createAuthor([
        '--password' => 'abc',
    ]);

    expect($exit)->toBe(1);
    expect(User::count())->toBe(0);
});

test('a half-created author is rolled back on failure', function () {
    // Sanity: a rejected creation leaves no user AND no orphan pages.
    createAuthor(['--username' => 'BAD NAME']);

    expect(User::count())->toBe(0);
    expect(App\Models\Page::count())->toBe(0);
});
