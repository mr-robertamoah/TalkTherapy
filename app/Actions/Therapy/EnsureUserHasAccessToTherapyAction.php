<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
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
            return;
        }

        throw new TherapyAccessDeniedException("You are not allowed to access therapy with id: {$therapy->id}", 422);
    }
}
