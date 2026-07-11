<?php

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function exportAuthor(string $username = 'brian'): User
{
    $user = User::factory()->create(['username' => $username]);
    $user->pages()->create(['slug' => 'about', 'body' => 'All about me.']);
    $user->pages()->create(['slug' => 'links', 'body' => '- a link']);

    return $user;
}

/**
 * Perform the export as $user and return the zip's contents as
 * ['path/inside/zip' => 'file contents', ...].
 *
 * @return array<string, string>
 */
function exportedFiles(User $user): array
{
    $response = test()->actingAs($user)->get(route('export'));
    $response->assertOk()->assertDownload();

    $zip = new ZipArchive;
    expect($zip->open($response->baseResponse->getFile()->getPathname()))->toBeTrue();

    $files = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $files[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
    }
    $zip->close();

    return $files;
}

/**
 * Pull one front-matter value out of an exported post file. The title is a
 * JSON string (valid YAML); everything else is a bare scalar.
 */
function frontMatterValue(string $file, string $key): mixed
{
    preg_match('/^'.preg_quote($key, '/').': (.*)$/m', $file, $matches);
    expect($matches)->not->toBeEmpty();

    return $key === 'title' ? json_decode($matches[1]) : $matches[1];
}

// --- Access -------------------------------------------------------------------

test('a guest cannot download an export', function () {
    $this->get(route('export'))->assertRedirect(route('login'));
});

// --- Contents -----------------------------------------------------------------

test('the export contains published posts, drafts, and both pages', function () {
    $author = exportAuthor();
    Post::factory()->for($author)->published()->create(['slug' => 'shipped']);
    Post::factory()->for($author)->create(['slug' => 'still-cooking']); // draft

    $files = exportedFiles($author);

    expect($files)->toHaveKeys([
        'posts/shipped.md',
        'posts/still-cooking.md',
        'about.md',
        'links.md',
    ]);
    expect($files['about.md'])->toBe('All about me.');
});

test('the export never contains another author\'s writing', function () {
    $author = exportAuthor('alice');
    $other = exportAuthor('bob');
    Post::factory()->for($author)->published()->create(['slug' => 'alice-post']);
    Post::factory()->for($other)->create([
        'slug' => 'bob-secret-draft',
        'body' => 'bob private words',
    ]);
    $other->pages()->where('slug', 'about')->update(['body' => 'bob about page']);

    $files = exportedFiles($author);

    expect($files)->toHaveKey('posts/alice-post.md')
        ->not->toHaveKey('posts/bob-secret-draft.md');
    expect(implode('', $files))
        ->not->toContain('bob private words')
        ->not->toContain('bob about page');
});

// --- Fidelity ------------------------------------------------------------------

test('a title with quotes, colons, and unicode survives the front-matter', function () {
    $author = exportAuthor();
    $title = 'She said: "it\'s done" — 完了 ✓';
    Post::factory()->for($author)->create(['title' => $title, 'slug' => 'tricky']);

    $files = exportedFiles($author);

    expect(frontMatterValue($files['posts/tricky.md'], 'title'))->toBe($title);
});

test('the post body is preserved exactly, below the front-matter', function () {
    $author = exportAuthor();
    $body = "# My heading\n\nSome **bold** text.\n\n---\n\nA thematic break above.";
    Post::factory()->for($author)->create(['slug' => 'faithful', 'body' => $body]);

    $file = exportedFiles($author)['posts/faithful.md'];

    // Everything after the closing front-matter delimiter is the body verbatim.
    [, , $exported] = explode("---\n", $file, 3);
    expect($exported)->toBe($body."\n");
});

test('front-matter reflects the post lifecycle', function () {
    $author = exportAuthor();
    Post::factory()->for($author)->create(['slug' => 'a-draft']);
    Post::factory()->for($author)->published()->create(['slug' => 'a-published']);

    $files = exportedFiles($author);

    expect(frontMatterValue($files['posts/a-draft.md'], 'status'))->toBe('draft');
    expect(frontMatterValue($files['posts/a-draft.md'], 'published_at'))->toBe('null');
    expect(frontMatterValue($files['posts/a-published.md'], 'status'))->toBe('published');
    expect(frontMatterValue($files['posts/a-published.md'], 'published_at'))
        ->not->toBe('null');
});
