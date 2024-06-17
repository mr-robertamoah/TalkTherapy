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
        if (!in_array($createSessionDTO->session->status, [SessionStatusEnum::in_session->value, SessionStatusEnum::in_session_confirmation->value]))
            throw new SessionException("'{$createSessionDTO->session->name}' session must be in session in order to change it's current session.", 422);

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