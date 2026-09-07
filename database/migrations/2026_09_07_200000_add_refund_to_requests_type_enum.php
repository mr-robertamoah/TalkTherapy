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
        // `type` is a native enum column -- adding RequestTypeEnum::refund (TT-7.7a/SCRUM-249)
        // requires re-applying the full current value list here, same as every prior addition to
        // this enum (see 2026_09_03_100000_add_session_schedule_proposal_to_requests_type_enum.php's
        // own comment for why this is invisible under Pest's SQLite test DB but not against a
        // real MySQL database).
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
