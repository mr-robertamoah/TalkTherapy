<?php

use App\Enums\RefundStatusSourceEnum;
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
        // `source` is a native enum column -- adding RefundStatusSourceEnum::initiate
        // (TT-7.7d/SCRUM-252) requires re-applying the full current value list here, same as
        // every prior addition to a native enum column in this codebase (e.g.
        // 2026_09_07_200000_add_refund_to_requests_type_enum.php).
        Schema::table('refund_status_histories', function (Blueprint $table) {
            $table->enum('source', RefundStatusSourceEnum::values())->change();
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
