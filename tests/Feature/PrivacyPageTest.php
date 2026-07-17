<?php

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the privacy page renders its policy', function () {
    $this->get('/privacy')
        ->assertOk()
        ->assertSee('Privacy')
        ->assertSee('no analytics, no tracking, no ads');
});

test('the footer links to privacy from the landing page and from a blog', function () {
    $author = User::factory()->create(['username' => 'brian']);
    Post::factory()->for($author)->published()->create();

    $this->get('/')->assertOk()->assertSee(route('privacy'), escape: false);
    $this->get('/@brian')->assertOk()->assertSee(route('privacy'), escape: false);
});
