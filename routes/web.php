<?php

use App\Http\Controllers\AppearanceController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\MarkdownPreviewController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicBlogController;
use App\Http\Controllers\PublishController;
use App\Http\Middleware\EnsureAuthorNotSuspended;
use App\Http\Middleware\PublicContentSecurityPolicy;
use Illuminate\Support\Facades\Route;

Route::middleware(PublicContentSecurityPolicy::class)->group(function () {
    Route::get('/', function () {
        return view('welcome');
    });

    Route::get('/acceptable-use', function () {
        return view('acceptable-use');
    })->name('acceptable-use');
});

// The author's private workspace. No 'verified' middleware: this is an
// invite-only host with no email-verification flow. EnsureAuthorNotSuspended
// ends the session of an author suspended after logging in.
Route::middleware(['auth', EnsureAuthorNotSuspended::class])->group(function () {
    // Dashboard landing page = the posts index.
    Route::get('/dashboard', [PostController::class, 'index'])->name('dashboard');

    // Posts live under /dashboard/posts/*. `show`/`index` are omitted:
    // the dashboard route above is the index, and public viewing is Phase 5.
    Route::prefix('dashboard')->group(function () {
        // Composer preview. Declared before the resource so the literal
        // 'preview' segment can never be mistaken for a {post} parameter.
        Route::post('posts/preview', MarkdownPreviewController::class)
            ->name('posts.preview');

        Route::resource('posts', PostController::class)
            ->except(['show', 'index'])
            ->names('posts');

        // Publish / unpublish a post.
        Route::post('posts/{post}/publish', [PublishController::class, 'store'])
            ->name('posts.publish');
        Route::delete('posts/{post}/publish', [PublishController::class, 'destroy'])
            ->name('posts.unpublish');

        // Pages (About, Links) are edited by slug, scoped to the author.
        Route::get('pages/{slug}/edit', [PageController::class, 'edit'])->name('pages.edit');
        Route::put('pages/{slug}', [PageController::class, 'update'])->name('pages.update');

        // Export: everything the author has written, as a zip of Markdown.
        Route::get('export', ExportController::class)->name('export');

        // Appearance: theme + font for the author's public blog.
        Route::get('appearance', [AppearanceController::class, 'edit'])->name('appearance.edit');
        Route::put('appearance', [AppearanceController::class, 'update'])->name('appearance.update');
    });

    // Breeze profile management (kept at /profile).
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

/*
| Public blog routes at /@{username}. The literal '@' prefix guarantees these
| can never collide with app routes like /login or /dashboard. The username
| pattern is constrained to our real rule so junk paths don't hit the DB.
|
| Order matters: the /about and /links page routes are declared BEFORE the
| catch-all {slug} post route, so those reserved words resolve to pages.
*/
Route::pattern('author', '[a-z0-9_]+');

// The strict CSP applies to the whole reader-facing surface (see the
// middleware for why the dashboard is excluded).
Route::middleware(PublicContentSecurityPolicy::class)->group(function () {
    Route::get('/@{author}', [PublicBlogController::class, 'home'])->name('blog.home');

    // Reserved words, declared before the catch-all {slug} post route so they
    // resolve to their own handlers rather than being read as a post slug.
    Route::get('/@{author}/about', [PublicBlogController::class, 'about'])->name('blog.about');
    Route::get('/@{author}/links', [PublicBlogController::class, 'links'])->name('blog.links');
    Route::get('/@{author}/feed', [PublicBlogController::class, 'feed'])->name('blog.feed');
    Route::get('/@{author}/sitemap.xml', [PublicBlogController::class, 'sitemap'])->name('blog.sitemap');

    Route::get('/@{author}/{slug}', [PublicBlogController::class, 'post'])->name('blog.post');
});
