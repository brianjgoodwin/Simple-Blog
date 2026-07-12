<?php

use App\Models\Invite;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

// Pest helpers are global functions — prefixed to avoid collisions.
function registrationInput(Invite $invite, array $overrides = []): array
{
    return array_merge([
        'code' => $invite->displayCode(),
        'name' => 'Robin Tester',
        'username' => 'robin',
        'email' => 'robin@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ], $overrides);
}

test('the register page renders and pre-fills the code from the link', function () {
    $this->get('/register?code=Abcd-Efgh-Jkmn')
        ->assertOk()
        ->assertSee('value="Abcd-Efgh-Jkmn"', false)
        ->assertSee(route('acceptable-use'));
});

test('logged-in authors are redirected away from the register page', function () {
    $this->actingAs(User::factory()->create())
        ->get('/register')
        ->assertRedirect('/dashboard');
});

test('a valid invite code registers an author', function () {
    $invite = Invite::factory()->create();

    // The code is submitted in its distributed Xxxx-Xxxx-Xxxx form — this
    // also covers the normalization (dash-stripping) path.
    $this->post('/register', registrationInput($invite))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticated();

    $user = User::where('username', 'robin')->firstOrFail();
    expect($user->pages()->orderBy('slug')->pluck('slug')->all())->toBe(['about', 'links']);

    $invite->refresh();
    expect($invite->isUsed())->toBeTrue()
        ->and($invite->used_by_id)->toBe($user->id);
});

test('a registered account matches an artisan-created one', function () {
    $this->artisan('author:create', [
        '--name' => 'CLI Author',
        '--username' => 'cliauthor',
        '--email' => 'cli@example.com',
        '--password' => 'password123',
    ])->assertSuccessful();

    $this->post('/register', registrationInput(Invite::factory()->create()));

    $cli = User::where('username', 'cliauthor')->firstOrFail();
    $web = User::where('username', 'robin')->firstOrFail();

    $shape = fn (User $user) => [
        'pages' => $user->pages()->orderBy('slug')->pluck('slug')->all(),
        'theme' => $user->theme,
        'font' => $user->font,
        'suspended' => $user->isSuspended(),
    ];

    expect($shape($web))->toEqual($shape($cli))
        ->and(Hash::check('password123', $web->password))->toBeTrue();
});

test('an unknown code is rejected honestly', function () {
    $this->post('/register', registrationInput(Invite::factory()->make())) // make(): never saved
        ->assertSessionHasErrors(['code' => 'That invite code is not valid.']);

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

test('a used code is rejected honestly', function () {
    $invite = Invite::factory()->used()->create();

    $this->post('/register', registrationInput($invite))
        ->assertSessionHasErrors(['code' => 'That invite code has already been used.']);

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

test('a code is consumed exactly once', function () {
    $invite = Invite::factory()->create();

    $this->post('/register', registrationInput($invite));
    auth()->logout();

    $this->post('/register', registrationInput($invite, [
        'username' => 'secondtester',
        'email' => 'second@example.com',
    ]))->assertSessionHasErrors('code');

    expect(User::count())->toBe(1)
        ->and(User::where('username', 'secondtester')->exists())->toBeFalse();
});

test('the shared username rules apply on the web form', function () {
    // Uppercase — rejected by the same rules author:create uses.
    $this->post('/register', registrationInput(Invite::factory()->create(), ['username' => 'Robin']))
        ->assertSessionHasErrors('username');

    // Taken — rejected by the unique rule.
    User::factory()->create(['username' => 'robin']);
    $this->post('/register', registrationInput(Invite::factory()->create()))
        ->assertSessionHasErrors('username');
});

test('registration is throttled', function () {
    $invite = Invite::factory()->make();

    foreach (range(1, 5) as $attempt) {
        $this->post('/register', registrationInput($invite))->assertStatus(302);
    }

    $this->post('/register', registrationInput($invite))->assertStatus(429);
});
