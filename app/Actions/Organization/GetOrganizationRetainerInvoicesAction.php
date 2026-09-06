<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationDTO;
use App\Enums\PaginationEnum;
use App\Models\OrganizationInvoice;
use Illuminate\Pagination\LengthAwarePaginator;

// TT-7.3b-j/SCRUM-241: the retainer half of the org-admin reconciliation view -- mirrors
// GetOrganizationFinancedTransactionsAction's own org-scoped-list shape (TT-6.6a). Newest period
// first (an org cares most about its CURRENT open period's accrued balance and the most recent
// settlement outcome), unlike the transactions list above, which orders by created_at.
class GetOrganizationRetainerInvoicesAction extends Action
{
    public function execute(OrganizationDTO $dto): LengthAwarePaginator
    {
        return OrganizationInvoice::query()
            ->where('organization_id', $dto->organization->id)
            // Not lines.session -- OrganizationInvoiceLineResource's own sessionLabel is built
            // directly off session_id (security-engineer finding: never the session's own
            // client-authored name), so eager-loading the session relation itself is unneeded.
            ->with(['lines.counsellor'])
            ->orderByDesc('period_start')
            ->paginate(PaginationEnum::preferencesPagination->value);
    }
}
