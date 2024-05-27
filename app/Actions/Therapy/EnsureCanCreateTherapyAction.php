<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\DTOs\CreateTherapyDTO;
use App\Exceptions\CannotCreateTherapyException;

class EnsureCanCreateTherapyAction extends Action
{
    public function execute(CreateTherapyDTO $createTherapyDTO)
    {
        if (
            $createTherapyDTO->user->isAdmin() ||
            $createTherapyDTO->user->isAdult() ||
            $createTherapyDTO->user->guardians()->count()
            // TODO not banned from creating or suspending from the app
        ) return;

        throw new CannotCreateTherapyException("You are not allowed to create a therapy because you seem to be a minor without a guardian.", 422);
    }
}