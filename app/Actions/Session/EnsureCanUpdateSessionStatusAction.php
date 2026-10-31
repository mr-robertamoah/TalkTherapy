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
        ) {
            return;
        }

        // SCRUM-339: never names the session, and matches EnsureSessionExistsAction -- this refusal
        // also reaches people with no access to it.
        throw new SessionException('You are not allowed to do that with this session.', 422);
    }
}
