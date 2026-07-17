<?php

use App\Models\Post;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // Cached render of `body` (Markdown -> HTML). `body` stays the
            // canonical content; this is a derived read-cache so the public
            // pages and the Atom feed don't re-run the Markdown pipeline on
            // every hit. Populated in Post's single render path (model saving);
            // rebuilt for all rows by `php artisan posts:rerender`.
            $table->text('body_html')->nullable()->after('body');
        });

        // Backfill posts written before this column existed. A backfill is a
        // mechanical re-render, not an edit, so withoutTimestamps keeps it from
        // bumping updated_at (the feed's <updated> reads it). saveQuietly is
        // enough because renderBodyHtml() has already set the value.
        Post::withoutTimestamps(function () {
            Post::query()->chunkById(100, function ($posts) {
                foreach ($posts as $post) {
                    $post->renderBodyHtml();
                    $post->saveQuietly();
                }
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('body_html');
        });
    }
};
