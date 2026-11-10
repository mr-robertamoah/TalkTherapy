<?php

namespace App\Actions\Transaction;

use App\Actions\Action;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;

// TT-7.3b-c/SCRUM-234 (reviewer finding): the ONE place "a Session resolves to its parent
// Therapy/GroupTherapy, anything else resolves to itself" happens -- previously duplicated
// verbatim across TransactionController::redirectUrlFor(), EnsureOrganizationCanPayForModelAction,
// ChargeOrganizationForModelAction, and TransactionService::initiateCharge(). Kept as its own
// action (not a private method on one of those) specifically so the routing decision in
// TransactionService and the hard precondition in ChargeOrganizationForModelAction can never
// silently drift apart by being edited independently.
class ResolveTransactionSubjectAction extends Action
{
    // Nullable return, not just nullable input -- a Session's own `for` relation is occasionally
    // null in the wild (defensively handled at every existing call site via `?->`), so this must
    // preserve that rather than turning a previously-tolerated null into a hard TypeError.
    public function execute(Therapy|GroupTherapy|Session|null $for): Therapy|GroupTherapy|null
    {
        return $for instanceof Session ? $for->for : $for;
    }
}
