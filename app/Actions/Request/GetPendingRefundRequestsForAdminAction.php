<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Enums\PaginationEnum;
use App\Enums\RequestTypeEnum;
use App\Models\Request;
use App\Models\Transaction;
use App\Models\User;
use App\Support\AdminSearch;
use Illuminate\Pagination\LengthAwarePaginator;

// TT-7.7c/SCRUM-251: the admin-facing list this whole review queue hangs off of. Deliberately its
// own dedicated query, not RequestService::getRequests() -- that method's `to`/`from` scoping
// (whereTo($user)) can never surface a refund request, since its `to` is always null by design
// (TT-7.7a: any admin may respond).
class GetPendingRefundRequestsForAdminAction extends Action
{
    public function execute(?User $user, ?string $search = null): LengthAwarePaginator
    {
        EnsureCanManageRefundRequestsAction::new()->execute($user);

        // Reviewer finding: `for` is the Transaction, but RefundRequestResource also reads the
        // TRANSACTION's own `for` (the Therapy/Session, for its `subjectName` field) -- without
        // `for.for` eager-loaded too, that was a clean +1-query-per-row N+1 on this page.
        return Request::query()
            ->whereType(RequestTypeEnum::refund->value)
            ->wherePending()
            // SCRUM-390: the payer's names and username, or the payment's reference.
            ->when($search, fn ($query, $term) => $query->where(fn ($query) => $query
                ->whereHasMorph('from', [User::class], fn ($query) => AdminSearch::person($query, $term))
                ->orWhereHasMorph('for', [Transaction::class], fn ($query) => $query->whereContains('reference', $term))))
            ->with(['from', 'for.for'])
            ->latest()
            ->paginate(PaginationEnum::preferencesPagination->value)
            ->withQueryString();
    }
}
