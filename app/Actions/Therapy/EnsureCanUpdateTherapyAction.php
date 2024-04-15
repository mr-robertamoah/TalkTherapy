<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\DTOs\CreateTherapyDTO;
use App\Exceptions\TherapyException;

class EnsureCanUpdateTherapyAction extends Action
{
    public function execute(CreateTherapyDTO $createTherapyDTO)
    {
        if (
            $createTherapyDTO->user->isAdmin() ||
            $createTherapyDTO->user->is($createTherapyDTO->therapy->addedby)
        ) return;

        throw new TherapyException("You are not allowed to update therapy with name: {$createTherapyDTO->therapy->name}.", 422);
    }
}