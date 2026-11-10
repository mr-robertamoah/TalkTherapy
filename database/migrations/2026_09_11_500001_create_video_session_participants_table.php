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
        // TT-3.1a/SCRUM-274: an audit-log row per join/leave cycle -- deliberately NOT a single
        // mutable "current participant" row, so a participant who disconnects and rejoins several
        // times leaves a full trail (architect recommendation: coarse audit facts only, no live
        // connection state). "Currently in the room" is derived (left_at IS NULL AND
        // video_sessions.ended_at IS NULL for that participant's latest row), never a separate
        // persisted flag that could drift from the actual join/leave events.
        //
        // Polymorphic participant, not a plain user_id, deliberately mirroring Session's own
        // for_type/for_id morph convention -- designed for N participants from day one (architect
        // recommendation) even though TT-3.1 only ever has 2, so TT-3.2 (multi-party, not yet
        // scoped) only relaxes a business-rule constant rather than needing new schema.
        Schema::create('video_session_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_session_id')->constrained();
            $table->morphs('participant');
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_session_participants');
    }
};
