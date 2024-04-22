<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\DTOs\CreateSessionDTO;
use App\Exceptions\SessionException;

class EnsureCanCreateSessionAction extends Action
{
    public function execute(CreateSessionDTO $createSessionDTO)
    {
        $type = $createSessionDTO->for->isTherapy ? 'therapy' : 'group therapy';

        if ($createSessionDTO->for->activeSession)
            throw new SessionException("You cannot create a session because the {$type} with name: {$createSessionDTO->for->name} has an active session. End the session to continue.", 422);

        if (
            $createSessionDTO->user->isAdmin() ||
            (
                $createSessionDTO->user->counsellor &&
                $createSessionDTO->for->isCounsellor($createSessionDTO->user->counsellor)
            )
            // TODO not banned from creating or suspending from the app
        ) return;

        throw new SessionException("You are not allowed to create a session for {$type} with name: {$createSessionDTO->for->name}.", 422);
    }
}