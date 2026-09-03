<?php

use App\Models\CounsellorPayout;
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
        // TT-7.6c/SCRUM-227: which payout batch last claimed this earning -- nullable (unset
        // until a payout is triggered), and reassigned (not cleared) if a failed payout returns
        // this row to `pending` and a later payout re-claims it, so the row always shows its most
        // recent claim history, not just its first.
        Schema::table('counsellor_earnings', function (Blueprint $table) {
            $table->foreignIdFor(CounsellorPayout::class)->nullable()->after('counsellor_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('counsellor_earnings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('counsellor_payout_id');
        });
    }
};
