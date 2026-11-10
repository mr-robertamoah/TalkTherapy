<?php

use App\Enums\LinkTypeEnum;
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
        // `type` is a native enum column -- adding organizationSelfApply to LinkTypeEnum requires
        // re-applying the full current value list here (mirrors
        // 2024_05_25_081451_change_column_type_on_requests_table.php's identical pattern for
        // RequestTypeEnum), otherwise a real (non-migrate:fresh) database's column would reject
        // the new value at the DB level.
        Schema::table('links', function (Blueprint $table) {
            $table->enum('type', LinkTypeEnum::values())->change();
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
