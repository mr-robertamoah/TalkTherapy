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
        // The TOCTOU race this constraint closes (SCRUM-99) may already have produced
        // duplicate (guardian_id, ward_id) rows before this migration runs -- adding the
        // unique index would otherwise fail outright against that pre-existing data. Keep the
        // earliest row per pair (the original, legitimate relationship) and drop the rest.
        $this->deleteDuplicateRows();

        Schema::table('guardianship', function (Blueprint $table) {
            $table->unique(['guardian_id', 'ward_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('guardianship', function (Blueprint $table) {
            $table->dropUnique(['guardian_id', 'ward_id']);
        });
    }

    private function deleteDuplicateRows(): void
    {
        DB::table('guardianship')
            ->select('guardian_id', 'ward_id')
            ->groupBy('guardian_id', 'ward_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->each(function ($duplicate) {
                $idsNewestFirst = DB::table('guardianship')
                    ->where('guardian_id', $duplicate->guardian_id)
                    ->where('ward_id', $duplicate->ward_id)
                    ->orderByDesc('id')
                    ->pluck('id');

                DB::table('guardianship')
                    ->whereIn('id', $idsNewestFirst->slice(0, -1))
                    ->delete();
            });
    }
};
