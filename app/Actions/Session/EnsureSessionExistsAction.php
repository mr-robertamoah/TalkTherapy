<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\DTOs\CreateSessionDTO;
use App\Exceptions\SessionException;

class EnsureSessionExistsAction extends Action
{
    public function execute(CreateSessionDTO $createSessionDTO)
    {
        if ($createSessionDTO->session) {
            return;
        }

        // SCRUM-339: the same answer every session action gives a caller it refuses -- otherwise the
        // difference reveals which session ids exist.
        throw new SessionException('You are not allowed to do that with this session.', 422);
    }
}
