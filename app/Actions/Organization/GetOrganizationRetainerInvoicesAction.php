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
            // lines.session feeds OrganizationInvoiceLineResource's sessionLabel (its start date
            // only, never the client-authored name -- SCRUM-385). withTrashed() so a session
            // soft-deleted after settlement still shows its date.
            ->with([
                'lines.counsellor',
                'lines.session' => fn ($query) => $query->withTrashed(),
            ])
            ->orderByDesc('period_start')
            ->paginate(PaginationEnum::preferencesPagination->value);
    }
}
