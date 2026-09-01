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
        // SCRUM-197: security-engineer review found that SessionNote's edit-grace-window (see
        // GuardsPrivateNoteEditWindow) originally derived "when did this session end" from
        // Session::updated_at -- but that column gets freely re-touched by the existing
        // /sessions/{id}/in_session, /end, /fail, /abandon endpoints (none of which are
        // idempotent against an already-terminal session), letting a note's author trivially
        // reset or indefinitely extend its own edit window, defeating the whole point of the
        // grace period. ended_at is set exactly once, the first time a session reaches a
        // terminal status (HELD/FAILED/ABANDONED -- see ChangeSessionStatusAction), and is never
        // reset afterwards, even if status later gets replayed/flipped back.
        Schema::table('sessions', function (Blueprint $table) {
            $table->timestamp('ended_at')->nullable()->after('end_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->dropColumn('ended_at');
        });
    }
};
