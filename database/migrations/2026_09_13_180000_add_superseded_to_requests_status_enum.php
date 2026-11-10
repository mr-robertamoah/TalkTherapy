<?php

use App\Enums\RequestStatusEnum;
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
        // `status` is a native enum column -- adding RequestStatusEnum::superseded
        // (TT-4.11c/SCRUM-304) requires re-applying the full current value list here, same as
        // every prior addition to `requests`'s `type` enum (see
        // 2026_09_12_200000_add_dob_change_to_requests_type_enum.php's own comment).
        Schema::table('requests', function (Blueprint $table) {
            $table->enum('status', RequestStatusEnum::values())->default(RequestStatusEnum::pending->value)->change();
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
