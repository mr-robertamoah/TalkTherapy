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
        // The TOCTOU race this constraint closes (SCRUM-100) may already have produced
        // duplicate (counsellor_id, discussion_id) rows before this migration runs -- adding
        // the unique index would otherwise fail outright against that pre-existing data. Keep
        // the earliest row per pair (the original, legitimate membership) and drop the rest.
        $this->deleteDuplicateRows();

        Schema::table('counsellor_discussion', function (Blueprint $table) {
            $table->unique(['counsellor_id', 'discussion_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('counsellor_discussion', function (Blueprint $table) {
            $table->dropUnique(['counsellor_id', 'discussion_id']);
        });
    }

    private function deleteDuplicateRows(): void
    {
        DB::table('counsellor_discussion')
            ->select('counsellor_id', 'discussion_id')
            ->groupBy('counsellor_id', 'discussion_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->each(function ($duplicate) {
                $idsNewestFirst = DB::table('counsellor_discussion')
                    ->where('counsellor_id', $duplicate->counsellor_id)
                    ->where('discussion_id', $duplicate->discussion_id)
                    ->orderByDesc('id')
                    ->pluck('id');

                DB::table('counsellor_discussion')
                    ->whereIn('id', $idsNewestFirst->slice(0, -1))
                    ->delete();
            });
    }
};
