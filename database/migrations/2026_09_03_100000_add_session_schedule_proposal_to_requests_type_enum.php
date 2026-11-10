<?php

use App\Enums\RequestTypeEnum;
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
        // `type` is a native enum column -- adding RequestTypeEnum::sessionScheduleProposal
        // (SCRUM-206/TT-2.5a) requires re-applying the full current value list here, the same
        // way 2024_05_25_081451_change_column_type_on_requests_table.php and
        // 2026_08_28_600000_add_expiry_columns_to_requests_table.php did for earlier additions.
        // Missed when SCRUM-206 merged -- invisible under Pest's SQLite test DB (no enum
        // enforcement there), but a real (non-migrate:fresh) MySQL database's column would
        // reject/truncate the new value at the DB level without this (caught while building
        // TT-2.5b's accept/reject/counter fixtures against the real dev database).
        Schema::table('requests', function (Blueprint $table) {
            $table->enum('type', RequestTypeEnum::values())->change();
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
