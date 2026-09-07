<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Exceptions\OrganizationException;
use App\Models\OrganizationInvoice;
use App\Models\Transaction;
use App\Models\User;

// TT-7.3b-followup/SCRUM-245: admin-triggered re-attempt of a `failed` retainer settlement --
// SCRUM-236 shipped with no retry/dunning mechanism at all (a failed invoice stayed failed
// permanently), so this is the first way one can ever be retried. Deliberately thin: all the real
// settlement logic (locking, precondition checks, Transaction/job creation) already lives in
// SettleOrganizationInvoiceAction, relaxed to also accept a `failed` invoice specifically for this
// one deliberate manual entry point -- duplicating that logic here would only risk the two
// drifting apart.
class RetryOrganizationInvoiceSettlementAction extends Action
{
    public function execute(?User $user, ?OrganizationInvoice $invoice): ?Transaction
    {
        EnsureCanManageOrganizationBillingSuspensionAction::new()->execute($user);

        if (is_null($invoice)) {
            throw new OrganizationException('Organization invoice not found.', 404);
        }

        if (! $invoice->isFailed()) {
            throw new OrganizationException('Only a failed invoice settlement can be retried.', 422);
        }

        return SettleOrganizationInvoiceAction::new()->execute($invoice);
    }
}
