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
// branch), and no retry/org-suspension side effect belongs in this billing pipeline (that's
// TT-7.3b-f2's job).
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
    }
}
