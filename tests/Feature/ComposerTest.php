<?php

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// --- Markdown preview endpoint ----------------------------------------------

test('the preview endpoint requires authentication', function () {
    $this->post(route('posts.preview'), ['body' => '# Hi'])
        ->assertRedirect(route('login'));
});

test('the preview endpoint renders Markdown to HTML', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('posts.preview'), ['body' => 'Some **bold** text'])
        ->assertOk()
        ->assertSee('<strong>bold</strong>', escape: false);
});

test('the preview strips raw HTML exactly like the public page', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('posts.preview'), ['body' => "Hello <script>alert(1)</script>"])
        ->assertOk()
        ->assertDontSee('<script>', escape: false);
});

// --- Publish from the composer ----------------------------------------------

test('a new post can be saved and published in one step', function () {
    $author = User::factory()->create();

    $this->actingAs($author)->post(route('posts.store'), [
        'title' => 'Straight to press',
        'body' => 'Hello',
        'action' => 'publish',
    ]);

    $post = $author->posts()->firstOrFail();
    expect($post->status)->toBe(PostStatus::Published)
        ->and($post->published_at)->not->toBeNull();
});

test('storing without the publish action still creates a draft', function () {
    $author = User::factory()->create();

    $this->actingAs($author)->post(route('posts.store'), [
        'title' => 'Still a draft',
        'body' => 'Hello',
    ]);

    expect($author->posts()->firstOrFail()->status)->toBe(PostStatus::Draft);
});

test('editing a draft with Save & Publish saves the new content, then publishes', function () {
    $author = User::factory()->create();
    $post = Post::factory()->for($author)->create([
        'status' => PostStatus::Draft,
        'published_at' => null,
        'body' => 'old text',
    ]);

    $this->actingAs($author)->put(route('posts.update', $post), [
        'title' => $post->title,
        'body' => 'new text, published together',
        'action' => 'publish',
    ]);

    $post->refresh();
    expect($post->status)->toBe(PostStatus::Published)
        ->and($post->body)->toBe('new text, published together');
});

// --- Autosave (JSON) path ----------------------------------------------------

test('an autosave request gets JSON back instead of a redirect', function () {
    $author = User::factory()->create();
    $post = Post::factory()->for($author)->create([
        'status' => PostStatus::Draft,
        'published_at' => null,
    ]);

    $this->actingAs($author)
        ->putJson(route('posts.update', $post), [
            'title' => 'Autosaved title',
            'body' => 'autosaved body',
        ])
        ->assertOk()
        ->assertJson(['saved' => true]);

    $post->refresh();
    expect($post->title)->toBe('Autosaved title')
        ->and($post->body)->toBe('autosaved body');
});

test('an autosave with no title gets a 422, not a crash', function () {
    $author = User::factory()->create();
    $post = Post::factory()->for($author)->create([
        'status' => PostStatus::Draft,
        'published_at' => null,
    ]);

    $this->actingAs($author)
        ->putJson(route('posts.update', $post), ['title' => '', 'body' => 'x'])
        ->assertStatus(422);
});

test('an autosave cannot touch another author\'s post', function () {
    $owner = User::factory()->create();
    $post = Post::factory()->for($owner)->create();

    $this->actingAs(User::factory()->create())
        ->putJson(route('posts.update', $post), ['title' => 'hijack', 'body' => 'x'])
        ->assertForbidden();
});
