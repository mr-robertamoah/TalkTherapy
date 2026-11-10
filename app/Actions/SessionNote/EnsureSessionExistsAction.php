<?php

namespace App\Actions\SessionNote;

use App\Actions\Action;
use App\DTOs\CreateSessionNoteDTO;
use App\Exceptions\SessionNoteException;

class EnsureSessionExistsAction extends Action
{
    public function execute(CreateSessionNoteDTO $dto)
    {
        if ($dto->session) {
            return;
        }

        throw new SessionNoteException('Session was not found.', 422);
    }
}
