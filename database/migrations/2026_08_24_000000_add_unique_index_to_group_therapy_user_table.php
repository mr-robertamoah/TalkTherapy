<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The TOCTOU race this constraint closes (SCRUM-80) may already have produced
        // duplicate (group_therapy_id, user_id) rows before this migration runs -- adding the
        // unique index would otherwise fail outright against that pre-existing data. Keep the
        // earliest row per pair (the original, legitimate membership) and drop the rest.
        $this->deleteDuplicateRows();

        Schema::table('group_therapy_user', function (Blueprint $table) {
            $table->unique(['group_therapy_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('group_therapy_user', function (Blueprint $table) {
            $table->dropUnique(['group_therapy_id', 'user_id']);
        });
    }

    private function deleteDuplicateRows(): void
    {
        DB::table('group_therapy_user')
            ->select('group_therapy_id', 'user_id')
            ->groupBy('group_therapy_id', 'user_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->each(function ($duplicate) {
                $idsNewestFirst = DB::table('group_therapy_user')
                    ->where('group_therapy_id', $duplicate->group_therapy_id)
                    ->where('user_id', $duplicate->user_id)
                    ->orderByDesc('id')
                    ->pluck('id');

                DB::table('group_therapy_user')
                    ->whereIn('id', $idsNewestFirst->slice(0, -1))
                    ->delete();
            });
    }
};
