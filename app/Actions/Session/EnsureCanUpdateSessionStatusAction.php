<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\DTOs\CreateSessionDTO;
use App\Exceptions\SessionException;

class EnsureCanUpdateSessionStatusAction extends Action
{
    public function execute(CreateSessionDTO $createSessionDTO, string $type = 'start')
    {
        if (
            $createSessionDTO->user->isAdmin() ||
            $createSessionDTO->session->isParticipant($createSessionDTO->user)
        ) return;

        throw new SessionException("You cannot {$type} session with name: {$createSessionDTO->session->name}.", 422);
    }
}