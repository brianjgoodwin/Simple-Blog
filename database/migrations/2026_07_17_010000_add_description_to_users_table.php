<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A short, plain-text blog description (tagline). Shown under the blog name
     * on the public home page, carried as the Atom feed's <subtitle>, and used
     * as the home page's meta/OG description. Nullable — blogs without one are
     * exactly as before. Not author-Markdown: plain text, escaped on output.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('description', 200)->nullable()->after('font');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
