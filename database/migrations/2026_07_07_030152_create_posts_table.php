<?php

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
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->text('body')->nullable(); // Markdown source
            // 'draft' | 'published' in v1; room for 'unlisted' later.
            $table->string('status')->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            // Slugs are unique per author, not globally: two authors may each
            // have a post at /@them/hello.
            $table->unique(['user_id', 'slug']);

            // Drives the public blog-home query: an author's published posts,
            // newest first.
            $table->index(['user_id', 'status', 'published_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
