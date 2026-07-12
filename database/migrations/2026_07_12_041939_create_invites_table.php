<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 11: single-use invite codes gating registration.
     *
     * Deliberately dumb: an invite is valid iff used_at is null — that one
     * fact is the whole state machine (same doctrine as suspended_at). No
     * expires_at in v1; revoking an unused code = deleting the row. Codes
     * are stored in plaintext by decision, not oversight: hashed codes
     * couldn't be re-listed by invite:list, which would force code tracking
     * into a text file — a worse posture. Codes are not passwords; anyone
     * who can read this table can read users.
     */
    public function up(): void
    {
        Schema::create('invites', function (Blueprint $table) {
            $table->id();
            // The bare 12-character code (no dashes — those are added only
            // for display; lookups strip them from input).
            $table->string('code')->unique();
            $table->string('note')->nullable();
            $table->timestamp('used_at')->nullable();
            // Audit trail: which account came from this code. Survives as
            // null if that account is ever deleted.
            $table->foreignId('used_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invites');
    }
};
