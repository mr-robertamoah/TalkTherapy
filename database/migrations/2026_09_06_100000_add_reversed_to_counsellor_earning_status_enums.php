<?php

use App\Enums\CounsellorEarningStatusEnum;
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
        // Both `status` columns are native MySQL enums -- adding CounsellorEarningStatusEnum::reversed
        // (TT-7.3b-g/SCRUM-239) requires re-applying the full current value list on EACH of them,
        // the same way 2026_09_03_100000_add_session_schedule_proposal_to_requests_type_enum.php
        // did for `requests.type`. Invisible under Pest's SQLite test DB (no enum enforcement
        // there), but a real MySQL column would reject/truncate the new value without this.
        Schema::table('counsellor_earnings', function (Blueprint $table) {
            $table->enum('status', CounsellorEarningStatusEnum::values())->default(CounsellorEarningStatusEnum::pending->value)->change();
        });

        Schema::table('counsellor_earning_status_histories', function (Blueprint $table) {
            $table->enum('status', CounsellorEarningStatusEnum::values())->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
