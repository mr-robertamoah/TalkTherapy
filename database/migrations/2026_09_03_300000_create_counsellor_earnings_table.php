<?php

use App\Enums\CounsellorEarningShareBasisEnum;
use App\Enums\CounsellorEarningStatusEnum;
use App\Models\Counsellor;
use App\Models\Transaction;
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
        // One row per counsellor entitled to a share of a successful, personally-financed
        // Transaction (TT-7.6b/SCRUM-226) -- generated once, snapshotted at creation, never
        // recalculated later even if a GroupTherapy's composition/percentage subsequently
        // changes. Typed columns (mirrors organization_counsellor_compensations' convention for
        // this exact kind of snapshotted business fact), not a JSON blob, since TT-7.3b needs to
        // query/aggregate these later.
        Schema::create('counsellor_earnings', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Transaction::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Counsellor::class)->constrained();
            // Minor units (pesewas/cents), same convention as transactions.amount -- never a float.
            $table->unsignedBigInteger('gross_amount');
            $table->unsignedBigInteger('fee_amount');
            $table->unsignedBigInteger('net_amount');
            $table->string('currency', 3);
            // Null for an individual Therapy/Session earning (always 100% to the sole counsellor).
            $table->enum('share_basis', CounsellorEarningShareBasisEnum::values())->nullable();
            // Snapshot of GroupTherapy.payment_data->sharePercentage at generation time -- null
            // when share_basis is EQUAL (or on an individual-therapy row).
            $table->unsignedSmallInteger('share_percentage')->nullable();
            $table->enum('status', CounsellorEarningStatusEnum::values())->default(CounsellorEarningStatusEnum::pending->value);
            $table->timestamps();

            // Idempotency backstop: RecordTransactionStatusAction's own terminal-status guard
            // already means a Transaction can only reach `success` once, but this makes a
            // duplicate generation attempt (e.g. a future retry path) a clean DB-level rejection
            // rather than a silent double-earning for the same counsellor.
            $table->unique(['transaction_id', 'counsellor_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('counsellor_earnings');
    }
};
