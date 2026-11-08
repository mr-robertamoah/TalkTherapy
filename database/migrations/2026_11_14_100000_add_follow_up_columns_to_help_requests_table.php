<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCRUM-33 (TT-4.4c): after a help chat.
// - invited_at / invited_by_id: the counsellor in the chat (or an administrator) invited the person to
//   start a regular therapy with that counsellor -- once a request.
// - transcript_deleted_at: the chat's messages and the person's note were deleted when the retention
//   period passed (PruneHelpChatTranscriptsAction); the request row itself stays, without content.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('help_requests', function (Blueprint $table) {
            $table->timestamp('invited_at')->nullable();
            // Who sent it (the counsellor's or an administrator's user id) -- for the record only, so no
            // foreign key or index, like the other *_by_id columns here.
            $table->unsignedBigInteger('invited_by_id')->nullable();
            $table->timestamp('transcript_deleted_at')->nullable();

            $table->index(['transcript_deleted_at', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::table('help_requests', function (Blueprint $table) {
            $table->dropIndex(['transcript_deleted_at', 'ended_at']);
            $table->dropColumn(['invited_at', 'invited_by_id', 'transcript_deleted_at']);
        });
    }
};
