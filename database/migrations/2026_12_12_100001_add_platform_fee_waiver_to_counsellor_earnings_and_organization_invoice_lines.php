<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // TT-7.10a/SCRUM-467: the waiver a fee was decided under (null: none, the fee was charged) and the fee it waived,
    // in minor units -- what the platform would otherwise have taken. A waived fee is recorded as fee_amount 0; an
    // earning copies these from the charge breakdown or invoice line it was made from, never deciding them again.
    public function up(): void
    {
        foreach (['counsellor_earnings', 'organization_invoice_lines'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('platform_fee_waiver_id')->nullable()->after('fee_amount')->constrained();
                $table->unsignedBigInteger('waived_fee_amount')->default(0)->after('platform_fee_waiver_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['counsellor_earnings', 'organization_invoice_lines'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('platform_fee_waiver_id');
                $table->dropColumn('waived_fee_amount');
            });
        }
    }
};
