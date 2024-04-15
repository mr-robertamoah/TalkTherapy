<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\DTOs\CreateSessionDTO;
use App\Exceptions\SessionException;

class EnsureCanCreateSessionAction extends Action
{
    public function execute(CreateSessionDTO $createSessionDTO)
    {
        if (
            $createSessionDTO->user->isAdmin() ||
            (
                $createSessionDTO->user->counsellor &&
                $createSessionDTO->for->isCounsellor($createSessionDTO->user->counsellor)
            )
            // TODO not banned from creating or suspending from the app
        ) return;

        throw new SessionException("You are not allowed to create a session for therapy with name: {$createSessionDTO->therapy->name}.", 422);
    }
}