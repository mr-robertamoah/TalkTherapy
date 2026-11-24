<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // TT-7.11c/SCRUM-472 (security review M1): every post-interaction star ever awarded, kept when the star is revoked.
    // A user who has an award for a post is never awarded for it again, so unliking and liking again can't re-date a
    // star into a new month, and the per-post and per-client caps count these rows, so churn can't reset them. Written
    // only by SyncPostInteractionStarAction, in the award's transaction. Deleting the post deletes its rows.
    public function up(): void
    {
        Schema::create('post_interaction_awards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('counsellor_id')->constrained()->cascadeOnDelete();
            $table->timestamp('awarded_at');

            $table->unique(['post_id', 'user_id']);
            // The per-client daily cap: one counsellor, one user, one day.
            $table->index(['counsellor_id', 'user_id', 'awarded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_interaction_awards');
    }
};
