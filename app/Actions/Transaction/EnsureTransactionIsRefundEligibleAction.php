<?php

namespace App\Actions\Transaction;

use App\Actions\Action;
use App\Enums\RefundStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Exceptions\TransactionException;
use App\Models\Request as ModelsRequest;
use App\Models\Transaction;

// TT-7.7a/SCRUM-249: the single eligibility gate every later refund sub-ticket (TT-7.7b's ask,
// TT-7.7c's approve, TT-7.7d's re-check at execution time) calls into -- mirrors this codebase's
// established "one Ensure*Action per hard precondition, re-checked at every step that matters"
// convention (e.g. EnsureCanInitiateChargeAction).
//
// Org-paid transaction eligibility (`transactions.organization_id` set) is included from the
// start, per the product-owner's own explicit decision (documentation/decision-log.md's
// 2026-09-02 SCRUM-223 entry) to resequence TT-7.6/TT-7.3b ahead of this epic specifically so
// that org-financed reconciliation (ReconcileOrgFinancedRefundAction, TT-7.3b-g/SCRUM-239) would
// already exist for TT-7.7d to call -- there is deliberately no organization_id check here.
//
// Full-refund-only for this epic -- this action takes no `amount` parameter; every caller that
// creates a Refund row uses the transaction's own `amount` in full (see RespondToRefundRequestAction).
//
// Security-engineer finding: this action's own reads (both `exists()` checks below) are plain,
// non-locking queries -- a caller that goes on to CREATE a Refund/Request row based on this
// action passing MUST first take `lockForUpdate()` on the Transaction row (or another shared
// resource that serializes concurrent callers for the SAME transaction) inside its own
// DB::transaction(), the same way RespondToRefundRequestAction does, or two concurrent callers
// could both pass this check before either commits.
class EnsureTransactionIsRefundEligibleAction extends Action
{
    private const ACTIVE_REFUND_STATUSES = [
        RefundStatusEnum::pending->value,
        RefundStatusEnum::processing->value,
        RefundStatusEnum::success->value,
    ];

    public function execute(Transaction $transaction): void
    {
        if (! $transaction->isSuccessful()) {
            throw new TransactionException('Only a successfully paid transaction can be refunded.', 422);
        }

        if ($transaction->refunds()->whereIn('status', self::ACTIVE_REFUND_STATUSES)->exists()) {
            throw new TransactionException('This transaction has already been refunded, or has a refund in progress.', 422);
        }

        if (
            ModelsRequest::query()
                ->whereFor($transaction)
                ->whereType(RequestTypeEnum::refund->value)
                ->wherePending()
                ->exists()
        ) {
            throw new TransactionException('A refund request for this transaction is already pending.', 422);
        }
    }
}
