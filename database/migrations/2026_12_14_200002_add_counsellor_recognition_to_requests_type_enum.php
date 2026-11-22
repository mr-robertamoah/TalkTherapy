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

    // Reversible, unlike the earlier additions: the consent requests are deleted, then the type is taken out of the
    // list. The three SCRUM-473 migrations are meant to roll back together (see the production checklist); if only
    // this one is, a consent left pending has no request to answer, so it is cleared.
    public function down(): void
    {
        DB::table('requests')->where('type', RequestTypeEnum::counsellorRecognition->value)->delete();

        if (Schema::hasTable('counsellor_recognitions')) {
            DB::table('counsellor_recognitions')->where('consent', 'pending')->update(['consent' => null]);
        }

        Schema::table('requests', function (Blueprint $table) {
            $table->enum('type', array_values(array_diff(
                RequestTypeEnum::values(),
                [RequestTypeEnum::counsellorRecognition->value],
            )))->change();
        });
    }
};
