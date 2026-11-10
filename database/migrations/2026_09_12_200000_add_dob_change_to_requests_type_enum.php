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
        // `type` is a native enum column -- adding RequestTypeEnum::dobChange (TT-4.10c/SCRUM-292)
        // requires re-applying the full current value list here, same as every prior addition to
        // this enum (see 2026_09_07_200000_add_refund_to_requests_type_enum.php's own comment).
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
