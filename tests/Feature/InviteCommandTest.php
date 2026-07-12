<?php

use App\Models\Invite;
use App\Models\User;

test('invite:generate creates codes with a note', function () {
    $this->artisan('invite:generate', ['count' => 3, '--note' => 'first batch'])
        ->assertSuccessful();

    expect(Invite::count())->toBe(3)
        ->and(Invite::where('note', 'first batch')->count())->toBe(3)
        ->and(Invite::whereNull('used_at')->count())->toBe(3);
});

test('invite:generate rejects a silly count', function () {
    $this->artisan('invite:generate', ['count' => 0])->assertFailed();
    $this->artisan('invite:generate', ['count' => 101])->assertFailed();

    expect(Invite::count())->toBe(0);
});

test('generated codes use the unambiguous alphabet', function () {
    $code = Invite::generateCode();

    expect(strlen($code))->toBe(Invite::CODE_LENGTH)
        ->and(preg_match('/^['.preg_quote(Invite::ALPHABET, '/').']+$/', $code))->toBe(1)
        // The lookalike characters are excluded by construction.
        ->and(str_contains(Invite::ALPHABET, '0'))->toBeFalse()
        ->and(str_contains(Invite::ALPHABET, 'O'))->toBeFalse()
        ->and(str_contains(Invite::ALPHABET, '1'))->toBeFalse()
        ->and(str_contains(Invite::ALPHABET, 'l'))->toBeFalse()
        ->and(str_contains(Invite::ALPHABET, 'I'))->toBeFalse();
});

test('normalization strips dashes and whitespace but preserves case', function () {
    expect(Invite::normalize(' Kk7m-Xw4r  Tn2p '))->toBe('Kk7mXw4rTn2p');
});

test('invite:list shows who used a code', function () {
    $author = User::factory()->create(['username' => 'dave']);

    $invite = Invite::factory()->create(['note' => 'for Dave']);
    $invite->used_at = now();
    $invite->used_by_id = $author->id;
    $invite->save();

    Invite::factory()->create(['note' => 'spare']);

    $this->artisan('invite:list')
        ->expectsOutputToContain('used by @dave')
        ->expectsOutputToContain('unused')
        ->assertSuccessful();
});
