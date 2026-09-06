<?php

use App\Models\OrganizationInvoiceLine;
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
        // TT-7.3b-e/SCRUM-236 (architect finding): the existing unique(['transaction_id',
        // 'counsellor_id']) constraint assumed at most one earning per counsellor per
        // Transaction -- true for the two existing branches (a personal-pay transaction has
        // exactly one counsellor's share), but false for a settled retainer invoice, where ONE
        // Transaction covers a whole period and the SAME counsellor can easily have several
        // retainer-covered sessions (lines) settled together. Nullable+unique here (permits many
        // nulls for the other two branches, unaffected) gives this third branch its own,
        // correctly-scoped idempotency guard: one earning per line, never per (transaction,
        // counsellor).
        Schema::table('counsellor_earnings', function (Blueprint $table) {
            $table->foreignIdFor(OrganizationInvoiceLine::class)->nullable()->unique()->after('counsellor_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('counsellor_earnings', function (Blueprint $table) {
            $table->dropConstrainedForeignIdFor(OrganizationInvoiceLine::class);
        });
    }
};
