<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\DTOs\CreateSessionDTO;
use App\Exceptions\SessionException;

class EnsureTherapyExistsAction extends Action
{
    public function execute(
        CreateSessionDTO $createSessionDTO
    ) {
        if ($createSessionDTO->for) {
            return;
        }

        // SCRUM-339: the same answer EnsureCanCreateSessionAction gives someone not allowed to create
        // a session for an existing therapy -- otherwise the difference reveals which ids exist.
        throw new SessionException('You are not allowed to create a session here.', 422);
    }
}
