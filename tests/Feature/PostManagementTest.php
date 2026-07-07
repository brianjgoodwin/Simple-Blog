<?php

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// --- Access control ---------------------------------------------------------

test('guests are redirected from the dashboard', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('guests cannot reach the new-post form', function () {
    $this->get(route('posts.create'))->assertRedirect(route('login'));
});

// --- Creating ---------------------------------------------------------------

test('an author can create a draft post', function () {
    $author = User::factory()->create();

    $response = $this->actingAs($author)->post(route('posts.store'), [
        'title' => 'My First Post',
        'body' => '# Hello',
    ]);

    $post = Post::first();

    expect($post)->not->toBeNull()
        ->and($post->user_id)->toBe($author->id)
        ->and($post->title)->toBe('My First Post')
        ->and($post->slug)->toBe('my-first-post')
        ->and($post->status)->toBe(PostStatus::Draft)
        ->and($post->published_at)->toBeNull();

    $response->assertRedirect(route('posts.edit', $post));
});

test('creating a post requires a title', function () {
    $author = User::factory()->create();

    $this->actingAs($author)
        ->post(route('posts.store'), ['title' => '', 'body' => 'x'])
        ->assertSessionHasErrors('title');

    expect(Post::count())->toBe(0);
});

test('slugs are unique per author but may repeat across authors', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    $this->actingAs($a)->post(route('posts.store'), ['title' => 'Hello World']);
    $this->actingAs($a)->post(route('posts.store'), ['title' => 'Hello World']);
    $this->actingAs($b)->post(route('posts.store'), ['title' => 'Hello World']);

    $slugsA = $a->posts()->pluck('slug')->sort()->values()->all();
    $slugB = $b->posts()->first()->slug;

    expect($slugsA)->toBe(['hello-world', 'hello-world-2']);
    expect($slugB)->toBe('hello-world'); // no collision across authors
});

// --- The multi-tenancy guarantee (the important one) ------------------------

test('an author cannot view the edit form for another author\'s post', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $post = Post::factory()->for($owner)->create();

    $this->actingAs($intruder)
        ->get(route('posts.edit', $post))
        ->assertForbidden(); // 403 via PostPolicy
});

test('an author cannot update another author\'s post', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $post = Post::factory()->for($owner)->create(['title' => 'Original']);

    $this->actingAs($intruder)
        ->put(route('posts.update', $post), ['title' => 'Hacked', 'body' => 'x'])
        ->assertForbidden();

    expect($post->fresh()->title)->toBe('Original'); // unchanged
});

test('an author cannot delete another author\'s post', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $post = Post::factory()->for($owner)->create();

    $this->actingAs($intruder)
        ->delete(route('posts.destroy', $post))
        ->assertForbidden();

    expect(Post::whereKey($post->id)->exists())->toBeTrue(); // still there
});

test('the dashboard only shows the author\'s own posts', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();

    Post::factory()->for($me)->create(['title' => 'Mine']);
    Post::factory()->for($other)->create(['title' => 'Theirs']);

    $this->actingAs($me)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Mine')
        ->assertDontSee('Theirs');
});

// --- Editing & deleting your own --------------------------------------------

test('an author can edit their own draft, and the slug tracks the title', function () {
    $author = User::factory()->create();
    $post = Post::factory()->for($author)->create(['title' => 'First', 'slug' => 'first']);

    $this->actingAs($author)
        ->put(route('posts.update', $post), ['title' => 'Second', 'body' => 'updated'])
        ->assertRedirect(route('posts.edit', $post));

    $post->refresh();
    expect($post->title)->toBe('Second')
        ->and($post->body)->toBe('updated')
        ->and($post->slug)->toBe('second'); // draft slug follows the title
});

test('an author can delete their own post', function () {
    $author = User::factory()->create();
    $post = Post::factory()->for($author)->create();

    $this->actingAs($author)
        ->delete(route('posts.destroy', $post))
        ->assertRedirect(route('dashboard'));

    expect(Post::count())->toBe(0);
});
