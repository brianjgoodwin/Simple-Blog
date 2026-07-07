<?php

use App\Http\Controllers\PostController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublishController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// The author's private workspace. No 'verified' middleware: this is an
// invite-only host with no email-verification flow.
Route::middleware('auth')->group(function () {
    // Dashboard landing page = the posts index.
    Route::get('/dashboard', [PostController::class, 'index'])->name('dashboard');

    // Posts live under /dashboard/posts/*. `show`/`index` are omitted:
    // the dashboard route above is the index, and public viewing is Phase 5.
    Route::prefix('dashboard')->group(function () {
        Route::resource('posts', PostController::class)
            ->except(['show', 'index'])
            ->names('posts');

        // Publish / unpublish a post.
        Route::post('posts/{post}/publish', [PublishController::class, 'store'])
            ->name('posts.publish');
        Route::delete('posts/{post}/publish', [PublishController::class, 'destroy'])
            ->name('posts.unpublish');
    });

    // Breeze profile management (kept at /profile).
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
