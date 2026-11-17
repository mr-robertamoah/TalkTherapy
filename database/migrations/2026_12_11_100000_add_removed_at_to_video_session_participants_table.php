<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // TT-3.3b/SCRUM-459: when the server took this join row's person out of the call
    // (EjectVideoParticipantAction -- a counsellor's removal, a suspension, a guardian or consent
    // change, leaving the group), as opposed to them leaving or the call ending. An automatic rejoin
    // after a connection drop is refused when the person's latest row in that call has it set, so a
    // removal they missed while offline can't be undone by their browser reconnecting.
    public function up(): void
    {
        Schema::table('video_session_participants', function (Blueprint $table) {
            $table->timestamp('removed_at')->nullable()->after('left_at');
        });
    }

    public function down(): void
    {
        Schema::table('video_session_participants', function (Blueprint $table) {
            $table->dropColumn('removed_at');
        });
    }
};
