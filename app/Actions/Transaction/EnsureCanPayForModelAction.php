<?php

namespace App\Actions\Transaction;

use App\Actions\Action;
use App\DTOs\TransactionDTO;
use App\Exceptions\TransactionException;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;

class EnsureCanPayForModelAction extends Action
{
    // Mirrors the ownership checks every other Therapy/GroupTherapy write path already uses
    // (EnsureCanUpdateTherapyAction, EnsureUserHasAccessToTherapyAction) rather than inventing a
    // new authorization rule: any signed-in user could otherwise generate a real Paystack
    // checkout link -- and see the price -- for a therapy/session belonging to someone else.
    public function execute(TransactionDTO $dto)
    {
        if (is_null($dto->user)) {
            throw new TransactionException('You must be signed in to make a payment.', 401);
        }

        $for = $dto->for instanceof Session ? $dto->for->for : $dto->for;

        if (! $for instanceof Therapy && ! $for instanceof GroupTherapy) {
            throw new TransactionException('You are not authorized to pay for this.', 403);
        }

        // The counsellor is a participant too (isParticipant() is true for both sides), but
        // paying for one's own service makes no sense -- only the client side may initiate a
        // charge. The `! $for instanceof Therapy || $for->counsellor_id` guard matters:
        // Therapy::isCounsellor() calls $this->counsellor->is(...) with no null check of its
        // own, so calling it on a Therapy with no counsellor assigned yet would crash instead
        // of cleanly returning false (GroupTherapy::isCounsellor() has no such issue, since it
        // queries a pivot rather than dereferencing a single relation).
        $isCounsellorForThis = $dto->user->counsellor
            && (! $for instanceof Therapy || $for->counsellor_id)
            && $for->isCounsellor($dto->user->counsellor);

        if (! $for->isParticipant($dto->user) || $isCounsellorForThis) {
            throw new TransactionException('You are not authorized to pay for this.', 403);
        }
    }
}
