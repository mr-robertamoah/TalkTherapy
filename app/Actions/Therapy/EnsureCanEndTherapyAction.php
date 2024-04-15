<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\DTOs\CreateTherapyDTO;
use App\Exceptions\TherapyException;

class EnsureCanEndTherapyAction extends Action
{
    public function execute(CreateTherapyDTO $createTherapyDTO)
    {
        if (
            $createTherapyDTO->user->isAdmin() ||
            (
                $createTherapyDTO->therapy->sessionsHeld &&
                (
                    $createTherapyDTO->user->is($createTherapyDTO->therapy->addedby) ||
                    $createTherapyDTO->user->is($createTherapyDTO->therapy->counsellor?->user)
                )
            ) ||
            $createTherapyDTO->therapy->sessionsHeld >= $createTherapyDTO->therapy->max_sessions
        ) return;

        throw new TherapyException("You are not allowed to end therapy with name: {$createTherapyDTO->therapy->name}. You are either not authorized or there are less than the required held sessions.", 422);
    }
}