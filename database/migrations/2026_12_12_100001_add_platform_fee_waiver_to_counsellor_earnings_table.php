<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // TT-7.10a/SCRUM-467: the waiver an earning's fee was decided under (null: none, the fee was taken) and the fee it
    // waived, in minor units -- what the platform would otherwise have taken. A waived fee is recorded as fee_amount 0.
    // An earning copies these from the charge breakdown or invoice line it was made from, never deciding them again.
    // A waiver referenced here is never deleted (restrictOnDelete) -- a period is ended instead.
    public function up(): void
    {
        Schema::table('counsellor_earnings', function (Blueprint $table) {
            $table->foreignId('platform_fee_waiver_id')->nullable()->after('fee_amount')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('waived_fee_amount')->default(0)->after('platform_fee_waiver_id');
        });
    }

    public function down(): void
    {
        Schema::table('counsellor_earnings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('platform_fee_waiver_id');
            $table->dropColumn('waived_fee_amount');
        });
    }
};
