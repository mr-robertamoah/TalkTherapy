<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\DTOs\CreateSessionDTO;
use App\Exceptions\SessionException;

class EnsureCanUpdateSessionAction extends Action
{
    public function execute(CreateSessionDTO $createSessionDTO)
    {
        if (
            $createSessionDTO->user->isAdmin() ||
            (
                $createSessionDTO->user->counsellor &&
                $createSessionDTO->user->counsellor->is($createSessionDTO->session->addedby)
            )
        ) return;

        throw new SessionException("You are not allowed to update session with name: {$createSessionDTO->session->name}.", 422);
    }
}