<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\Actions\Transaction\GrantPaymentAccessAction;
use App\DTOs\GetTherapyDTO;
use App\DTOs\GrantPaymentAccessDTO;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapyPerPaymentEnum;
use App\Enums\TransactionStatusEnum;
use App\Exceptions\PaymentRequiredException;
use App\Exceptions\TherapyAccessDeniedException;
use App\Models\Discussion;
use App\Models\PaymentAccessGrant;
use App\Models\Request;
use App\Models\Therapy;
use App\Models\User;

class EnsureUserHasAccessToTherapyAction extends Action
{
    public function execute(GetTherapyDTO $getTherapyDTO, string $type = 'therapy')
    {
        $therapy = $getTherapyDTO->$type;
        $user = $getTherapyDTO->user;

        if ($therapy->public) {
            return;
        }

        // A guest (null user) can never satisfy any of the below checks -- isParticipant()
        // is non-nullable-typed, so without this explicit guard a guest on a non-public
        // therapy would fail closed only by accident, via an uncaught TypeError the calling
        // controller's generic catch block happens to redirect (SCRUM-76).
        if (! $user) {
            throw new TherapyAccessDeniedException("You are not allowed to access therapy with id: {$therapy->id}", 422);
        }

        // SCRUM-219/TT-7.5a: the client (the therapy's own addedby, individual Therapy only --
        // GroupTherapy gating is TT-7.5b) is the only participant ever subject to the strict
        // payment gate below. Every other bypass in the big OR below (counsellor, admin,
        // guardian, pending-request-counsellor) grants unconditional access, unchanged.
        $isClient = $therapy->is_therapy && $therapy->addedby->is($user);

        if (
            $therapy->isParticipant($user) ||
            (
                $user->counsellor &&
                (
                    Request::query()
                        ->wherePending()
                        ->whereTo($user->counsellor)
                        ->whereHasMorph('for', [Discussion::class], function ($query) use ($therapy) {
                            $query->whereFor($therapy);
                        })
                        ->exists() ||
                    $user->counsellor->hasPendingRequestFor($therapy)
                )
            ) ||
            $user->isAdmin() ||
            (
                $therapy->is_therapy &&
                $user->isGuardianOf($therapy->addedby)
            ) ||
            (
                $therapy->is_group_therapy &&
                $user->isGuardianOfAUserFor($therapy)
            )
        ) {
            if ($isClient) {
                $this->ensureStrictPaymentGateSatisfied($therapy, $user);
            }

            return;
        }

        throw new TherapyAccessDeniedException("You are not allowed to access therapy with id: {$therapy->id}", 422);
    }

    // Only ever reached for the client on an individual, PAID, PER_THERAPY, strict-gated Therapy
    // -- PER_SESSION gating and MessageService's chat surfaces are SCRUM-220's job, and
    // GroupTherapy is TT-7.5b. Once a grant exists, this always returns without re-checking the
    // transaction's current status -- a later refund must never retroactively revoke access
    // already granted (SCRUM-215 decision #3), which is the entire reason PaymentAccessGrant
    // exists instead of a live Transaction.status check.
    private function ensureStrictPaymentGateSatisfied(Therapy $therapy, User $user): void
    {
        if (
            ! $therapy->strictPaymentGate ||
            $therapy->payment_type !== TherapyPaymentTypeEnum::paid->value ||
            data_get($therapy->payment_data, 'per') !== TherapyPerPaymentEnum::therapy->value
        ) {
            return;
        }

        $hasGrant = PaymentAccessGrant::query()
            ->where('user_id', $user->id)
            ->where('for_type', Therapy::class)
            ->where('for_id', $therapy->id)
            ->exists();

        if ($hasGrant) {
            return;
        }

        $successfulTransaction = $therapy->transactions()
            ->where('user_id', $user->id)
            ->where('status', TransactionStatusEnum::success->value)
            ->latest('created_at')
            ->first();

        if ($successfulTransaction) {
            GrantPaymentAccessAction::new()->execute(GrantPaymentAccessDTO::new()->fromArray([
                'user' => $user,
                'for' => $therapy,
                'transaction' => $successfulTransaction,
            ]));

            return;
        }

        throw new PaymentRequiredException("Payment is required to access therapy with id: {$therapy->id}", 402);
    }
}
