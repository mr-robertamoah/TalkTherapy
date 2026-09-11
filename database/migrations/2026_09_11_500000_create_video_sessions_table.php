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
        // TT-3.1a/SCRUM-274: one row per video "epoch" of an existing Session -- created on first
        // join, torn down (provider-side) when the underlying Session's video capability ends.
        // Deliberately holds only coarse audit facts (which provider, the provider's own room id,
        // when it started/ended); live/mid-call connection state (connected, muted, participant
        // count) is deliberately NOT persisted here at all -- it stays ephemeral (provider-side or
        // client-side only), a direct consequence of the product requirement that a transient
        // media disconnect must never mutate Session.status (see TT-3.1d).
        Schema::create('video_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained();
            // Recorded per-row (not just read from config('video.provider') at query time) so a
            // historical video_sessions row stays accurate even if the deployment's active
            // provider is later changed.
            $table->string('provider');
            $table->string('provider_room_id')->nullable();
            // Raw provider-specific metadata needed later to construct join credentials (e.g.
            // Chime's own Meeting response, which participant tokens are minted against) --
            // deliberately opaque/provider-shaped, not normalized into columns.
            $table->json('provider_meta')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_sessions');
    }
};
