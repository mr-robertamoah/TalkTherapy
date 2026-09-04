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
        // TT-7.3b-e/SCRUM-236: unique(['transaction_id', 'counsellor_id']) (TT-7.6b/SCRUM-226)
        // assumed at most one earning per counsellor per Transaction -- true for the
        // individual/group branches this was originally added for, but false for a settled
        // retainer invoice, where ONE Transaction covers a whole period and the SAME counsellor
        // can easily have several retainer-covered sessions (lines) settled together. Dropped
        // without losing real protection: GenerateCounsellorEarningsAction's own
        // `$transaction->earnings()->exists()` guard (still in place for the other two branches)
        // already prevents a second generation attempt for the whole transaction, which is what
        // this constraint was actually backstopping. The new, nullable+unique
        // organization_invoice_line_id column is the settled-invoice branch's own, correctly
        // scoped idempotency guard.
        // The composite unique index being dropped is currently the only index covering
        // transaction_id, which its own foreign key constraint relies on (InnoDB requires an
        // index on any FK column) -- a plain index must exist first, or MySQL refuses the drop.
        Schema::table('counsellor_earnings', function (Blueprint $table) {
            $table->index('transaction_id');
        });

        Schema::table('counsellor_earnings', function (Blueprint $table) {
            $table->dropUnique('counsellor_earnings_transaction_id_counsellor_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('counsellor_earnings', function (Blueprint $table) {
            $table->unique(['transaction_id', 'counsellor_id']);
        });

        Schema::table('counsellor_earnings', function (Blueprint $table) {
            $table->dropIndex(['transaction_id']);
        });
    }
};
