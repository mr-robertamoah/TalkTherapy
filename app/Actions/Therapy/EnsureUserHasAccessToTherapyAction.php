<?php

namespace App\Actions\Therapy;
use App\Actions\Action;
use App\DTOs\GetTherapyDTO;
use App\Exceptions\TherapyAccessDeniedException;
use App\Models\Discussion;
use App\Models\Request;

class EnsureUserHasAccessToTherapyAction extends Action
{

    public function execute(GetTherapyDTO $getTherapyDTO, String $type = 'therapy')
    {
        $therapy = $getTherapyDTO->$type;

        if (
            $therapy->public ||
            $therapy->isParticipant($getTherapyDTO->user) ||
            (
                $getTherapyDTO->user->counsellor && 
                (
                    Request::query()
                        ->wherePending()
                        ->whereTo($getTherapyDTO->user->counsellor)
                        ->whereHasMorph('for', [Discussion::class], function ($query) use ($therapy) {
                            $query->whereFor($therapy);
                        })
                        ->exists() ||
                    $getTherapyDTO->user->counsellor->hasPendingRequestFor($therapy)
                )
            ) ||
            $getTherapyDTO->user->isAdmin() ||
            (
                $therapy->is_therapy &&
                $getTherapyDTO->user->isGuardianOf($therapy->addedby)
            ) ||
            (
                $therapy->is_group_therapy &&
                $getTherapyDTO->user->isGuardianOfAUserFor($therapy)
            )
        ) return;

        throw new TherapyAccessDeniedException("You are not allowed to assess therapy with id: {$getTherapyDTO->therapy->id}", 422);
    }
}