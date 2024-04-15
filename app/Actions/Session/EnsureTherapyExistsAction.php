<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\DTOs\CreateSessionDTO;
use App\Exceptions\SessionException;

class EnsureTherapyExistsAction extends Action
{
    public function execute(
        CreateSessionDTO $createSessionDTO
    )
    {
        if ($createSessionDTO->for) return;

        throw new SessionException("Group\Individual therapy was not found.", 422);
    }
}