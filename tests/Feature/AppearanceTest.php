<?php

use App\Enums\BlogFont;
use App\Enums\Theme;
use App\Models\User;

// Pest test helpers are global functions, so the name is prefixed to avoid
// colliding with the other feature tests' author helpers.
function appearanceAuthor(): User
{
    return User::factory()->create(['username' => 'quinn']);
}

test('guests cannot open the appearance settings', function () {
    $this->get('/dashboard/appearance')->assertRedirect('/login');
});

test('an author can open the appearance settings', function () {
    $this->actingAs(appearanceAuthor())
        ->get('/dashboard/appearance')
        ->assertOk()
        ->assertSee('Appearance')
        ->assertSee('Theme')
        ->assertSee('Font');
});

test('an author can change their theme and font', function () {
    $author = appearanceAuthor();

    $this->actingAs($author)
        ->put('/dashboard/appearance', ['theme' => 'sage', 'font' => 'serif'])
        ->assertRedirect(route('appearance.edit'))
        ->assertSessionHas('status');

    $author->refresh();
    expect($author->theme)->toBe(Theme::Sage)
        ->and($author->font)->toBe(BlogFont::Serif);
});

test('an unknown theme or font is rejected', function () {
    $author = appearanceAuthor();

    $this->actingAs($author)
        ->put('/dashboard/appearance', ['theme' => 'hotdog-stand', 'font' => 'comic'])
        ->assertSessionHasErrors(['theme', 'font']);

    $author->refresh();
    expect($author->theme)->toBe(Theme::Default)
        ->and($author->font)->toBe(BlogFont::Sans);
});

test('theme and font cannot be set by mass assignment', function () {
    $author = appearanceAuthor();

    // Deliberately not fillable (same doctrine as suspended_at): a future
    // controller mass-assigning request input must not reach these columns.
    $author->update(['theme' => 'sage', 'font' => 'serif']);

    $author->refresh();
    expect($author->theme)->toBe(Theme::Default)
        ->and($author->font)->toBe(BlogFont::Sans);
});

test('the public blog carries the author\'s theme and font', function () {
    $author = appearanceAuthor();
    // Direct assignment: theme and font are deliberately not fillable.
    $author->theme = Theme::Dusk;
    $author->font = BlogFont::Serif;
    $author->save();

    $this->get('/@quinn')
        ->assertOk()
        ->assertSee('data-theme="dusk"', false)
        ->assertSee('font-serif');
});

test('a fresh author gets the default look', function () {
    appearanceAuthor();

    $this->get('/@quinn')
        ->assertOk()
        ->assertSee('data-theme="default"', false)
        ->assertSee('font-sans');
});
