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
        // SCRUM-218/TT-7.5a: records that a client was granted access to a strict-gated payable
        // (Therapy or Session, matching Transaction::for()'s own shape) exactly once. Deliberately
        // NOT an append-only/versioned history table (unlike organization_counsellor_compensations)
        // -- a row here is a permanent fact, never superseded, and is never derived from
        // transactions.status at read time: that field is live/mutable by design (see
        // transaction_status_histories), and a later refund (TT-7.7) must never retroactively
        // revoke access already granted (SCRUM-215 decision #3).
        Schema::create('payment_access_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->morphs('for');
            // Nullable: the grant itself is the source of truth for access; the transaction is
            // kept only for audit traceability (which charge originally earned this grant).
            $table->foreignId('transaction_id')->nullable()->constrained();
            $table->timestamp('granted_at');
            $table->timestamps();

            $table->unique(['user_id', 'for_type', 'for_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_access_grants');
    }
};
