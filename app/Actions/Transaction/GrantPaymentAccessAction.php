<?php

namespace App\Actions\Transaction;

use App\Actions\Action;
use App\DTOs\GrantPaymentAccessDTO;
use App\Models\PaymentAccessGrant;
use InvalidArgumentException;

class GrantPaymentAccessAction extends Action
{
    // Idempotent and race-safe: createOrFirst() catches the unique-constraint violation a
    // concurrent duplicate insert would otherwise raise (unlike firstOrCreate(), which is not
    // itself atomic), so two simultaneous requests granting the same (user, for) pair never both
    // attempt an insert. Once a grant row exists it is never mutated or deleted by this action --
    // calling this again for an already-granted pair is a safe no-op that returns the original row.
    //
    // This action assumes $dto->user/$dto->for are already validated, authorized models -- like
    // EnsureCanInitiateChargeAction assumes EnsureCanPayForModelAction already ran, resolving
    // *who* is allowed to gain access is the caller's job (SCRUM-219/220), not this one's. Because
    // a grant here is permanent (immune to a later refund, by design -- SCRUM-215 decision #3),
    // a caller that grants the wrong user/payable can never self-correct via a status change.
    public function execute(GrantPaymentAccessDTO $dto): PaymentAccessGrant
    {
        // Defense in depth, not the real gate check: a caller must only reach this action after
        // confirming payment succeeded, but if one *does* pass a transaction, refuse to silently
        // grant permanent access off the back of a pending/failed one.
        if ($dto->transaction && ! $dto->transaction->isSuccessful()) {
            throw new InvalidArgumentException('Cannot grant payment access from a non-successful transaction.');
        }

        return PaymentAccessGrant::createOrFirst(
            [
                'user_id' => $dto->user->id,
                'for_type' => $dto->for::class,
                'for_id' => $dto->for->id,
            ],
            [
                'transaction_id' => $dto->transaction?->id,
                'granted_at' => now(),
            ]
        );
    }
}
