<?php

use App\Enums\RefundStatusEnum;
use App\Models\Request as ModelsRequest;
use App\Models\Transaction;
use App\Models\User;
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
        // TT-7.7a/SCRUM-249: architect decision (documentation/decision-log.md's 2026-09-02
        // SCRUM-223 entry) -- a separate table for the refund itself, NOT a
        // TransactionStatusEnum::refunded case, since `transactions.status` already means "did
        // the charge succeed" for existing consumers (webhook amount/currency verification,
        // Transaction::isSuccessful()). Naturally 1:many per transaction (partial-refund-friendly
        // for later, even though this epic is full-refund-only for now).
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Transaction::class)->constrained()->cascadeOnDelete();
            // Nullable -- traces back to the client's ask (TT-7.7b), but this table's own schema
            // shouldn't hard-depend on that not-yet-built request-creation flow existing.
            $table->foreignIdFor(ModelsRequest::class, 'request_id')->nullable()->constrained('requests')->nullOnDelete();
            $table->foreignIdFor(User::class, 'requested_by_id')->constrained('users');
            // SCRUM-243's own lesson applied here: generated and persisted at Refund-row creation
            // time (RespondToRefundRequestAction, on approval), BEFORE TT-7.7d's queued job ever
            // calls Paystack -- a failure creating this row fails closed instead of a
            // successful-but-unrecorded refund call ever being possible.
            //
            // TT-7.7d/SCRUM-252 update: unlike this codebase's other Paystack-facing references
            // (e.g. CounsellorPayout.reference, sent to Paystack's transfer endpoint as its own
            // caller-supplied idempotency key), this ticket's own spike found Paystack's refund
            // endpoint takes NO caller-supplied idempotency reference at all -- it keys entirely
            // off the ORIGINAL transaction's own reference. This column is therefore internal/
            // audit-only: never sent to Paystack, and never used to correlate a refund.processed/
            // refund.failed webhook back to this row (that's done via the transaction's own
            // reference instead -- see ProcessPaystackWebhookJob).
            $table->string('reference')->unique();
            // Minor units, snapshotted from the transaction at approval time -- full-refund-only
            // for this epic, so this always equals the transaction's own `amount`.
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->text('reason')->nullable();
            $table->enum('status', RefundStatusEnum::values())->default(RefundStatusEnum::pending->value);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
