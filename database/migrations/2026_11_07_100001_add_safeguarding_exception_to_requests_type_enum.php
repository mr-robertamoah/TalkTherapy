<?php

use App\Enums\RequestTypeEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // SCRUM-381: `type` is a native enum column -- adding RequestTypeEnum::safeguardingException (a
    // counsellor asking the super administrator for a safeguarding exception) re-applies the full
    // current value list, like every earlier addition (2026_09_13_172003_add_age_verification_...).
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->enum('type', RequestTypeEnum::values())->change();
        });
    }

    // Left as is, like the earlier additions: narrowing the enum would fail on any row of this type.
    public function down(): void
    {
        //
    }
};
