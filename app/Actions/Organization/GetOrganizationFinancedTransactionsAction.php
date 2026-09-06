<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationDTO;
use App\Enums\PaginationEnum;
use App\Models\OrganizationInvoice;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\Transaction;
use Illuminate\Pagination\LengthAwarePaginator;

// TT-7.3b-j/SCRUM-241: mirrors GetOrganizationMembersAction/GetOrganizationCounsellorsAction's own
// org-scoped-list shape (TT-6.6a) -- the pay-per-use half of the org-admin reconciliation view.
// `organization_id` is only ever set on a Transaction that either financed a Therapy/Session/
// GroupTherapy payment on a member's behalf, or settled a retainer invoice (Transaction's own
// header comment) -- excluding `for_type = OrganizationInvoice` here is what keeps this list to
// the former only; retainer settlements are a separate section (GetOrganizationRetainerInvoicesAction),
// never mixed into the same table.
class GetOrganizationFinancedTransactionsAction extends Action
{
    public function execute(OrganizationDTO $dto): LengthAwarePaginator
    {
        $paginator = Transaction::query()
            ->where('organization_id', $dto->organization->id)
            ->where('for_type', '!=', OrganizationInvoice::class)
            ->with(['for', 'earnings.payout'])
            ->latest()
            ->paginate(PaginationEnum::preferencesPagination->value);

        // Reviewer finding: without this, OrganizationFinancedTransactionResource's own
        // ResolveTransactionSubjectAction call (Session -> its parent Therapy) and `->counsellor`
        // access are both lazy-loaded per row -- a real, confirmed N+1 (verified: 2 extra queries
        // per Session-backed row). loadMorph() (not a nested morphWith() closure) deliberately, so
        // each step only ever eager-loads a relation that actually exists on that specific type --
        // GroupTherapy has no singular counsellor() relation, and blindly dot-eager-loading
        // through a morphTo assuming Therapy would throw a BadMethodCall the one time a Session's
        // own `for` resolves to a GroupTherapy instead.
        $paginator->getCollection()->loadMorph('for', [
            Therapy::class => ['counsellor'],
            Session::class => [],
        ]);

        $sessionSubjects = $paginator->getCollection()
            ->pluck('for')
            ->filter(fn ($subject) => $subject instanceof Session);

        if ($sessionSubjects->isNotEmpty()) {
            $sessionSubjects->loadMorph('for', [
                Therapy::class => ['counsellor'],
            ]);
        }

        return $paginator;
    }
}
