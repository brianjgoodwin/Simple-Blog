<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10: per-author appearance settings for the public blog.
     *
     * Plain string columns validated against PHP enums (Theme, BlogFont) —
     * same pattern as posts.status. The defaults are exactly the pre-Phase-10
     * look, so deploying this changes nothing for existing blogs.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('theme')->default('default');
            $table->string('font')->default('sans');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['theme', 'font']);
        });
    }
};
