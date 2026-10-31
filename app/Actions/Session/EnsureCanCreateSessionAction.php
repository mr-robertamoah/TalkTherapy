<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\DTOs\CreateSessionDTO;
use App\Exceptions\SessionException;

class EnsureCanCreateSessionAction extends Action
{
    public function execute(CreateSessionDTO $createSessionDTO)
    {
        // SCRUM-210: authorize before anything that reveals the target's name or live status --
        // the target comes from client input with no visibility filter, and ids are guessable.
        // TODO not banned from creating or suspending from the app
        $isAllowed = $createSessionDTO->user->isAdmin() || (
            $createSessionDTO->user->counsellor &&
            $createSessionDTO->for->isCounsellor($createSessionDTO->user->counsellor)
        );

        if (! $isAllowed) {
            throw new SessionException('You are not allowed to create a session here.', 422);
        }

        // Only reached by someone already allowed to create sessions for it, so naming it is safe.
        if ($createSessionDTO->for->activeSession) {
            $type = $createSessionDTO->for->isTherapy ? 'therapy' : 'group therapy';

            throw new SessionException("You cannot create a session because the {$type} with name: {$createSessionDTO->for->name} has an active session. End the session to continue.", 422);
        }
    }
}
