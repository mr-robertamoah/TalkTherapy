<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Enums\OrganizationInvoiceStatusEnum;
use App\Enums\TransactionStatusEnum;
use App\Models\OrganizationInvoice;
use App\Models\Transaction;

// TT-7.3b-e/SCRUM-236: called from RecordTransactionStatusAction for EVERY terminal status a
// settlement transaction can reach (unlike GenerateCounsellorEarningsAction/
// CaptureOrganizationPaymentInstrumentAction, which only ever fire on success) -- a failed
// settlement charge must flip its invoice to `failed` too, so the periodic sweep stops
// re-claiming it as `open` and it becomes visible for manual follow-up. Lines are left untouched
// either way: earnings generation only fires on success (a new GenerateCounsellorEarningsAction
// branch).
//
// TT-7.3b-f2/SCRUM-238: this is also the org-suspension mechanism's ONE writer -- a failed
// settlement immediately suspends the org's billing standing (Organization::suspendBilling()),
// enforced at EnsureStrictPaymentGateSatisfiedAction's own retainer-bypass call site. Immediate,
// not after a grace period: SCRUM-236's own design has no retry/dunning logic at all for a
// failed invoice (it stays `failed` permanently, no automatic re-attempt), so a grace period
// would imply a reconsideration path that doesn't exist -- suspending on the first failure is
// the only choice consistent with that. A later, successful settlement of a DIFFERENT invoice
// does NOT automatically lift a suspension (single writer, this direction only) -- there is no
// dunning/auto-resume mechanism yet; lifting one is a manual, out-of-band action for now.
class UpdateOrganizationInvoiceStatusAction extends Action
{
    public function execute(Transaction $transaction, string $status): void
    {
        if (! $transaction->for instanceof OrganizationInvoice) {
            return;
        }

        $invoiceStatus = match ($status) {
            TransactionStatusEnum::success->value => OrganizationInvoiceStatusEnum::settled->value,
            TransactionStatusEnum::failed->value => OrganizationInvoiceStatusEnum::failed->value,
            default => null,
        };

        if (! $invoiceStatus) {
            return;
        }

        $transaction->for->update(['status' => $invoiceStatus]);

        if ($invoiceStatus === OrganizationInvoiceStatusEnum::failed->value) {
            // Always overwrites billing_suspended_at/billing_suspension_reason, even for an
            // already-suspended org -- intentional, not a bug: each further failure just refreshes
            // the timestamp/reason to the latest one, since there is no dunning/auto-resume
            // mechanism to preserve state across (see this file's own header comment).
            $transaction->for->organization->suspendBilling(
                "Retainer invoice settlement failed for the period starting {$transaction->for->period_start->toDateString()}."
            );
        }
    }
}
