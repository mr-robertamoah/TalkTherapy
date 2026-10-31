<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\DTOs\CreateSessionDTO;
use App\Exceptions\SessionException;

class EnsureCanUpdateSessionAction extends Action
{
    public function execute(CreateSessionDTO $createSessionDTO)
    {
        // SCRUM-339: authorize first, with a message that names nothing -- session ids are guessable
        // and loaded unfiltered, and session names are free text about therapy content.
        $isAllowed = $createSessionDTO->user->isAdmin() || (
            $createSessionDTO->user->counsellor &&
            $createSessionDTO->user->counsellor->is($createSessionDTO->session->addedby)
        );

        if (! $isAllowed) {
            throw new SessionException('You are not allowed to do that with this session.', 422);
        }

        if ($createSessionDTO->session->isNotUpdateable()) {
            throw new SessionException("'{$createSessionDTO->session->name}' session cannot be updated because it is either in session or has ended.", 422);
        }
    }
}
