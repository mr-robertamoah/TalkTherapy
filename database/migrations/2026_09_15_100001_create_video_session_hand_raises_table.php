<?php

use App\Models\VideoSessionParticipant;
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
        // TT-3.2f-b/SCRUM-319: deliberately its OWN table, not columns on video_session_participants
        // (that table's own migration explicitly rejects mutable "current status" columns) and not
        // folded into video_session_speaking_grants either -- raise/lower is current-state, mutable,
        // self-service-clearable, and has no history-shaped value to the business the way a grant's
        // full audit trail does (architect decision, 2026-09-14: forcing this append-only would be
        // over-engineering by analogy). "Currently raised" is whereNull('lowered_at') on the latest
        // row for a video_session_participant_id.
        Schema::create('video_session_hand_raises', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(VideoSessionParticipant::class)->constrained();
            $table->timestamp('raised_at');
            $table->timestamp('lowered_at')->nullable();
            $table->timestamps();

            // Guards against a duplicate open raise for the same participant (app-level check
            // backed by this index) and serves the counsellor-facing "who's currently queued" read.
            // Explicit short name -- the auto-generated one exceeds MySQL's 64-character limit
            // (same issue found and fixed in the sibling video_session_speaking_grants migration).
            $table->index(['video_session_participant_id', 'lowered_at'], 'vshr_participant_lowered_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_session_hand_raises');
    }
};
