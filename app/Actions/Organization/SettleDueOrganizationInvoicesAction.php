<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Enums\OrganizationInvoiceStatusEnum;
use App\Models\OrganizationInvoice;
use Illuminate\Support\Facades\Log;
use Throwable;

// TT-7.3b-e/SCRUM-236: finds every retainer invoice whose period has closed and is still
// `open`, settling each independently -- try/catch per invoice (same isolation as the two
// compensation-request sweeps) so one org's failure (a missing payment instrument, a
// Paystack error) can never block or crash settlement for every other org's invoice in the
// same run. SettleOrganizationInvoiceAction itself does the real claim-and-dispatch work;
// this is only the periodic trigger.
// SCRUM-376: moved out of AppService.
class SettleDueOrganizationInvoicesAction extends Action
{
    public function execute(): void
    {
        OrganizationInvoice::query()
            ->where('status', OrganizationInvoiceStatusEnum::open->value)
            // A plain Y-m-d string, matching how period_end is actually stored (the model's own
            // `date:Y-m-d` cast, not a bare 'date' cast) -- comparing against a raw Carbon
            // instance here would bind its full datetime string representation instead, which
            // still happens to string-compare correctly against a same-day period_end but is
            // needlessly fragile to rely on.
            ->where('period_end', '<', now()->toDateString())
            ->get()
            ->each(function (OrganizationInvoice $invoice) {
                try {
                    SettleOrganizationInvoiceAction::new()->execute($invoice);
                } catch (Throwable $exception) {
                    Log::error('Failed to settle an organization retainer invoice.', [
                        'organizationInvoiceId' => $invoice->id,
                        'exception' => $exception->getMessage(),
                    ]);
                }
            });
    }
}
