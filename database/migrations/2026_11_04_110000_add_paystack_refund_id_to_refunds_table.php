<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // SCRUM-378: Paystack's own id for the refund, from the POST /refund response -- the only key
    // GET /refund/{id} accepts, so ProcessRefundJob can look a refused retry's outcome up. A string,
    // not an integer: it's Paystack's identifier, only ever echoed back to Paystack. Null until (and
    // unless) a refund call has returned one.
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->string('paystack_refund_id', 64)->nullable()->after('reference');
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropColumn('paystack_refund_id');
        });
    }
};
