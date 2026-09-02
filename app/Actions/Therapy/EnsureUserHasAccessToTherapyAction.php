<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\Actions\Transaction\EnsureStrictPaymentGateSatisfiedAction;
use App\DTOs\GetTherapyDTO;
use App\Exceptions\TherapyAccessDeniedException;
use App\Models\Discussion;
use App\Models\Request;

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
            // SCRUM-220: page-load is always the PER_THERAPY-payable case (no single Session is
            // in view here) -- the PER_SESSION case is gated separately, per-Session, inside
            // MessageService (EnsureUserCanAccessTherapyContentAction), not here.
            if ($isClient) {
                EnsureStrictPaymentGateSatisfiedAction::new()->execute($therapy, $user);
            }

            return;
        }

        throw new TherapyAccessDeniedException("You are not allowed to access therapy with id: {$therapy->id}", 422);
    }
}
