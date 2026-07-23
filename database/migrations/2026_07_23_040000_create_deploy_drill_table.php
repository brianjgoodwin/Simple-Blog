<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * THROWAWAY FIXTURE — do not merge this to main.
     *
     * This exists solely to exercise the deploy script's `migrate: run` branch,
     * which nothing else does: a real deploy only runs migrations when a commit
     * adds one, so without a fixture that code path ships untested.
     *
     * It is deliberately inert. A standalone table that no model, route, or
     * query references, so applying it cannot affect the running site, and a
     * failed drill leaves nothing behind that matters. The `drill_` prefix keeps
     * it unmistakable in `sqlite3 .tables` if it is ever left applied.
     *
     * Contrast with the broken migration that motivated this (2020_03_03_add_posts.php,
     * dropped before it could ship): that one had no imports, re-created an
     * existing table, had no down(), and carried a timestamp that sorted it ahead
     * of Laravel's own baseline. This one is what a correct migration looks like.
     */
    public function up(): void
    {
        Schema::create('deploy_drill', function (Blueprint $table) {
            $table->id();
            $table->string('note');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * A working down() is the point, not decoration. The drill's cleanup path
     * uses `migrate:rollback` for the ordinary case; restoring the pre-deploy
     * snapshot is the fallback that must also be proven, because a migration is
     * not guaranteed reversible in general and the snapshot always is.
     */
    public function down(): void
    {
        Schema::dropIfExists('deploy_drill');
    }
};
