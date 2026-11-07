<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCRUM-113 (devops review): a group's active counsellors are looked up by group and state on every
// ranked Home and Public list (PreferenceRanking) and by the membership checks -- the table had only
// its primary key, so each lookup scanned it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('counsellor_group_therapy', function (Blueprint $table) {
            $table->index(['group_therapy_id', 'state', 'counsellor_id'], 'counsellor_group_therapy_group_state_index');
        });
    }

    public function down(): void
    {
        Schema::table('counsellor_group_therapy', function (Blueprint $table) {
            $table->dropIndex('counsellor_group_therapy_group_state_index');
        });
    }
};
