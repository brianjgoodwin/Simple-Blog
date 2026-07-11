<?php

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Any change here is deliberate: the policy is this strict because the
// public pages load one same-origin stylesheet and nothing else.
const EXPECTED_CSP = "default-src 'none'; style-src 'self'; img-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'";

function cspAuthor(): User
{
    $user = User::factory()->create(['username' => 'dana']);
    $user->pages()->create(['slug' => 'about', 'body' => '']);
    $user->pages()->create(['slug' => 'links', 'body' => '']);
    Post::factory()->for($user)->published()->create(['slug' => 'a-post']);

    return $user;
}

test('the strict CSP header is on every reader-facing page', function () {
    cspAuthor();

    foreach (['/', '/acceptable-use', '/@dana', '/@dana/a-post', '/@dana/about', '/@dana/links'] as $url) {
        $this->get($url)
            ->assertOk()
            ->assertHeader('Content-Security-Policy', EXPECTED_CSP);
    }
});

test('the CSP header also covers 404s raised inside the public controller', function () {
    $author = cspAuthor();

    // Unknown slug and suspended author both abort inside the controller,
    // so the response passes back through the middleware. (An UNKNOWN
    // author 404s earlier, during route-model binding, before route
    // middleware runs — that response carries no CSP, which is fine: the
    // 404 page renders no author content. Documented in the middleware.)
    $this->get('/@dana/no-such-post')
        ->assertNotFound()
        ->assertHeader('Content-Security-Policy', EXPECTED_CSP);

    $author->suspended_at = now();
    $author->save();

    $this->get('/@dana')
        ->assertNotFound()
        ->assertHeader('Content-Security-Policy', EXPECTED_CSP);
});

test('the dashboard is not covered by the public CSP', function () {
    // Alpine needs eval-style expression evaluation and Breeze loads
    // webfonts; the public policy would break the dashboard outright.
    $this->actingAs(cspAuthor());

    $this->get('/dashboard')
        ->assertOk()
        ->assertHeaderMissing('Content-Security-Policy');
});

test('the acceptable-use page renders its rules and promises', function () {
    $this->get('/acceptable-use')
        ->assertOk()
        ->assertSee('Acceptable use')
        ->assertSee('no analytics, no tracking, no ads');
});

test('the landing page links to the acceptable-use page', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee(route('acceptable-use'), escape: false);
});
