<?php

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// --- Publishing -------------------------------------------------------------

test('an author can publish their own draft', function () {
    $author = User::factory()->create();
    $post = Post::factory()->for($author)->create([
        'status' => PostStatus::Draft,
        'published_at' => null,
    ]);

    $this->actingAs($author)
        ->post(route('posts.publish', $post))
        ->assertRedirect(route('posts.edit', $post));

    $post->refresh();
    expect($post->status)->toBe(PostStatus::Published)
        ->and($post->published_at)->not->toBeNull();
});

test('an author can unpublish their own post back to draft', function () {
    $author = User::factory()->create();
    $post = Post::factory()->for($author)->published()->create();

    $this->actingAs($author)
        ->delete(route('posts.unpublish', $post))
        ->assertRedirect(route('posts.edit', $post));

    expect($post->refresh()->status)->toBe(PostStatus::Draft);
});

// --- The frozen-slug guarantee (the important one) --------------------------

test('publishing freezes the slug: editing the title afterwards does not change the URL', function () {
    $author = User::factory()->create();

    // Create a draft the normal way so it gets a real slug.
    $this->actingAs($author)->post(route('posts.store'), ['title' => 'My Great Post']);
    $post = Post::first();
    expect($post->slug)->toBe('my-great-post');

    // Publish it.
    $this->actingAs($author)->post(route('posts.publish', $post));

    // Now edit the title. The slug must NOT follow the new title.
    $this->actingAs($author)->put(route('posts.update', $post), [
        'title' => 'A Completely Different Title',
        'body' => 'updated body',
    ]);

    $post->refresh();
    expect($post->title)->toBe('A Completely Different Title') // title changed
        ->and($post->slug)->toBe('my-great-post');            // slug frozen
});

test('a draft slug still tracks the title (only publishing freezes it)', function () {
    $author = User::factory()->create();

    $this->actingAs($author)->post(route('posts.store'), ['title' => 'First Title']);
    $post = Post::first();

    // Still a draft: editing the title SHOULD update the slug.
    $this->actingAs($author)->put(route('posts.update', $post), [
        'title' => 'Second Title',
        'body' => 'x',
    ]);

    expect($post->refresh()->slug)->toBe('second-title');
});

// --- published_at is preserved across a round-trip --------------------------

test('re-publishing after an unpublish keeps the original publication date', function () {
    $author = User::factory()->create();
    $post = Post::factory()->for($author)->create([
        'status' => PostStatus::Draft,
        'published_at' => null,
    ]);

    // First publish stamps published_at.
    $this->actingAs($author)->post(route('posts.publish', $post));
    $originalDate = $post->refresh()->published_at;
    expect($originalDate)->not->toBeNull();

    // Unpublish, then publish again.
    $this->actingAs($author)->delete(route('posts.unpublish', $post));
    $this->actingAs($author)->post(route('posts.publish', $post));

    // The date must be the ORIGINAL one, not a new "now".
    expect($post->refresh()->published_at->equalTo($originalDate))->toBeTrue();
});

// --- Authorization still holds ----------------------------------------------

test('an author cannot publish another author\'s post', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $post = Post::factory()->for($owner)->create(['status' => PostStatus::Draft]);

    $this->actingAs($intruder)
        ->post(route('posts.publish', $post))
        ->assertForbidden();

    expect($post->refresh()->status)->toBe(PostStatus::Draft); // unchanged
});

test('an author cannot unpublish another author\'s post', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $post = Post::factory()->for($owner)->published()->create();

    $this->actingAs($intruder)
        ->delete(route('posts.unpublish', $post))
        ->assertForbidden();

    expect($post->refresh()->status)->toBe(PostStatus::Published); // unchanged
});
