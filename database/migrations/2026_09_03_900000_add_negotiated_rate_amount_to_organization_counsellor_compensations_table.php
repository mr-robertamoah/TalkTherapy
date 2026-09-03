<?php

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
        // TT-7.3b-b0/SCRUM-232: `basis: NEGOTIATED_RATE` has existed as a valid enum value since
        // TT-6.4b, but nothing anywhere ever stored the actual negotiated number -- "70% of a
        // negotiated rate" resolved to "70% of nothing". No separate currency column, same as
        // `basis: COUNSELLOR_RATE`'s own percentage math -- always implicitly the currency of
        // whatever transaction/session this compensation is ultimately applied to (the same
        // deferred cross-currency gap already flagged for `type: FIXED`'s own `currency` column).
        Schema::table('organization_counsellor_compensations', function (Blueprint $table) {
            $table->unsignedBigInteger('negotiated_rate_amount')->nullable()->after('basis');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organization_counsellor_compensations', function (Blueprint $table) {
            $table->dropColumn('negotiated_rate_amount');
        });
    }
};
