<?php

namespace App\Actions\Transaction;

use App\Actions\Action;
use App\Enums\CounsellorEarningStatusEnum;
use App\Enums\CounsellorEarningStatusSourceEnum;
use App\Exceptions\TransactionException;
use App\Models\CounsellorEarning;
use App\Models\Transaction;
use App\Notifications\OrganizationFinancedTransactionRefundedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

// TT-7.3b-g/SCRUM-239: callable-only reconciliation hook for an org-financed transaction being
// refunded -- TT-7.7d (not yet built) will invoke this once it exists, per the "org-paid
// transactions trigger TT-7.3b's reconciliation here" note on that ticket's own row in
// documentation/implementation_plan.md. No live UI trigger exists yet; this is independently
// testable via direct invocation today, exactly as this ticket's own scope calls for.
//
// Deliberately generic across BOTH org-billing modes -- a pay-per-use transaction has exactly one
// CounsellorEarning; a retainer SETTLEMENT transaction (`for` an OrganizationInvoice) has one per
// line, fanned out by GenerateCounsellorEarningsAction's own settled-invoice branch. This action
// never distinguishes the two: it just reverses every earning still tied to $transaction that is
// still safely reversible.
//
// Interpretation of the ticket's own "adjusts the org's recorded ledger/invoice line" wording:
// CounsellorEarning IS the actual financial ledger entry (the org's own OrganizationInvoiceLine,
// once a retainer period has settled, is an immutable historical record of what was actually
// invoiced and charged -- mutating it after the fact would corrupt that history, and TT-7.7a's
// own architect decision already keeps refund tracking in a separate, not-yet-built `refunds`
// table rather than mutating existing settled records). Reversing the earning is the correct and
// sufficient "ledger" adjustment this hook makes.
class ReconcileOrgFinancedRefundAction extends Action
{
    public function execute(Transaction $transaction): void
    {
        if (is_null($transaction->organization_id)) {
            throw new TransactionException('Only an org-financed transaction can be reconciled for a refund.', 422);
        }

        // Reviewer + security-engineer finding (both independently): a soft-deleted financing
        // Organization must not crash this action -- Transaction::organization() is a plain
        // belongsTo, which Eloquent's default query excludes trashed rows from, so a refund
        // reconciled well after the org was deactivated would otherwise dereference null.
        $organization = $transaction->organization()->withTrashed()->first();

        if (is_null($organization)) {
            Log::warning('Cannot reconcile an org-financed refund -- the financing organization no longer exists.', [
                'transaction_id' => $transaction->id,
                'organization_id' => $transaction->organization_id,
            ]);

            return;
        }

        // Reviewer + security-engineer finding (both independently, HIGH severity): reads and
        // updates happen inside one locked transaction, mirroring TriggerCounsellorPayoutAction's
        // own idiom -- without this, two concurrent/replayed invocations for the same Transaction
        // could both read a `pending` earning before either commits, double-reversing it (a
        // duplicate statusHistories row, a duplicate notification).
        $needsManualReview = DB::transaction(function () use ($transaction) {
            $earnings = CounsellorEarning::query()
                ->where('transaction_id', $transaction->id)
                ->lockForUpdate()
                ->get();

            $needsManualReview = false;

            foreach ($earnings as $earning) {
                if ($earning->status === CounsellorEarningStatusEnum::pending->value) {
                    // Reversal or flag, never silent deletion (the ticket's own explicit
                    // requirement) -- the row and its full status history stay, so the audit
                    // trail shows exactly what happened and when.
                    $earning->update(['status' => CounsellorEarningStatusEnum::reversed->value]);

                    $earning->statusHistories()->create([
                        'status' => CounsellorEarningStatusEnum::reversed->value,
                        'source' => CounsellorEarningStatusSourceEnum::refundReconciliation->value,
                        'message' => "Reversed -- transaction {$transaction->reference} was refunded.",
                    ]);

                    continue;
                }

                // Security-engineer + reviewer finding (both independently, HIGH severity):
                // `processing` is NOT safely reversible the way `pending` is -- it means
                // TriggerCounsellorPayoutAction has already claimed this earning into a specific
                // CounsellorPayout whose amount is fixed and whose Paystack transfer may already be
                // in flight. RecordCounsellorPayoutStatusAction's own later resolution of that
                // payout does a BLANKET `$payout->earnings()->update(...)` with no status guard --
                // it would silently overwrite a `reversed` status back to `paidOut` (money sent
                // anyway, with no trace the reversal was clobbered) or `pending` (the reconciliation
                // undone entirely). Treated exactly like an already-`paidOut` earning instead:
                // flagged for manual follow-up, never auto-mutated.
                if (in_array($earning->status, [CounsellorEarningStatusEnum::processing->value, CounsellorEarningStatusEnum::paidOut->value], true)) {
                    $needsManualReview = true;

                    Log::warning('Cannot automatically reverse an org-financed transaction\'s earning -- a payout has already claimed or paid it out. Flagging for manual reconciliation.', [
                        'transaction_id' => $transaction->id,
                        'counsellor_earning_id' => $earning->id,
                        'earning_status' => $earning->status,
                    ]);
                }

                // Already REVERSED: no-op -- a repeated/replayed reconciliation attempt for the
                // same transaction finds nothing left to reverse, mirroring this codebase's
                // established "state machine, not a boolean, closes the race/replay" convention.
                // FAILED is not a real resting state on this column today (RecordCounsellorPayoutStatusAction
                // moves a failed payout's earnings straight back to `pending`, never leaves `failed`
                // persisted here) -- if that ever changes, this branch would need revisiting.
            }

            return $needsManualReview;
        });

        // Notification is queued (ShouldQueue) -- dispatched only after the transaction above has
        // actually committed, same reasoning as RecordCounsellorPayoutStatusAction's own identical
        // comment: a worker could otherwise pick up the notification before the reversed rows it
        // describes are visible on its own connection.
        $admins = $organization->admins;

        if ($admins->isEmpty()) {
            Log::warning('Cannot notify of an org-financed refund -- this organization has no admins.', [
                'transaction_id' => $transaction->id,
                'organization_id' => $transaction->organization_id,
            ]);

            return;
        }

        Notification::send($admins, new OrganizationFinancedTransactionRefundedNotification($transaction, $needsManualReview));
    }
}
