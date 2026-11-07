<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Enums\PaginationEnum;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

// TT-7.3b-followup/SCRUM-245: the admin-facing list this whole follow-up hangs off of -- without
// it, an admin would have no way to even discover which organizations are suspended, let alone
// act on one. Most-recently-suspended first (an admin cares most about active/growing issues).
class GetBillingSuspendedOrganizationsForAdminAction extends Action
{
    public function execute(?User $user, ?string $search = null): LengthAwarePaginator
    {
        // Reviewer finding: the controller's own inline admin check was this action's ONLY
        // guard -- re-checked here too (mirrors the two write actions' own independent
        // EnsureCanManageOrganizationBillingSuspensionAction call, and PayoutService::getPayoutsForAdmin()'s
        // identical defense-in-depth precedent), so a future caller that skips the controller
        // can't leak suspension reasons/invoice amounts with zero authorization.
        EnsureCanManageOrganizationBillingSuspensionAction::new()->execute($user);

        // SCRUM-387: and organisations with a failed invoice that aren't suspended (the overview's
        // count is both) -- suspended ones first, most recent first, then the rest.
        return Organization::query()
            ->needsBillingAttention()
            // SCRUM-390: by name (grouped, so it can't escape needsBillingAttention()).
            ->when($search, fn ($query, $term) => $query->whereContains('name', $term))
            ->with(['latestFailedInvoice', 'paymentInstrument'])
            ->orderByRaw('billing_suspended_at is null')
            ->orderByDesc('billing_suspended_at')
            ->latest('id')
            ->paginate(PaginationEnum::preferencesPagination->value)
            ->withQueryString();
    }
}
