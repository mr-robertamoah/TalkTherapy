<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\DTOs\CreateSessionDTO;
use App\Enums\SessionStatusEnum;
use App\Exceptions\SessionException;

class EnsureCanChangeSessionTopicAction extends Action
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

        if (! in_array($createSessionDTO->session->status, [SessionStatusEnum::in_session->value, SessionStatusEnum::in_session_confirmation->value])) {
            throw new SessionException("'{$createSessionDTO->session->name}' session must be in session in order to change it's current session.", 422);
        }
    }
}
