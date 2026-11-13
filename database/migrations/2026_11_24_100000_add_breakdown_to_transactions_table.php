<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // SCRUM-420: an organisation's card charge records how its amount splits between the counsellors (each one's
    // net and fee share), worked out when it's charged. Earnings are generated from this, not recomputed when the
    // payment is confirmed: a group's counsellors or terms may have changed in between. Null for every other
    // transaction.
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->json('breakdown')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('breakdown');
        });
    }
};
