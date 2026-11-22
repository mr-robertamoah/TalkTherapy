<?php

use App\Enums\RequestTypeEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // TT-7.11d1/SCRUM-473: `type` is a native enum column -- adding RequestTypeEnum::counsellorRecognition (a
    // counsellor's featuring consent) re-applies the full current value list, like every earlier addition
    // (2026_11_07_100001_add_safeguarding_exception_...).
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->enum('type', RequestTypeEnum::values())->change();
        });
    }

    // Reversible, unlike the earlier additions: the consent requests go with the recognitions they were for (that
    // table is dropped by the same rollback), then the type is taken out of the list.
    public function down(): void
    {
        DB::table('requests')->where('type', RequestTypeEnum::counsellorRecognition->value)->delete();

        Schema::table('requests', function (Blueprint $table) {
            $table->enum('type', array_values(array_diff(
                RequestTypeEnum::values(),
                [RequestTypeEnum::counsellorRecognition->value],
            )))->change();
        });
    }
};
