<?php

namespace App\Actions\SessionNote;

use App\Actions\Action;
use App\DTOs\CreateSessionNoteDTO;
use App\Exceptions\SessionNoteException;

class EnsureSessionNoteExistsAction extends Action
{
    public function execute(CreateSessionNoteDTO $dto)
    {
        if ($dto->sessionNote) {
            return;
        }

        throw new SessionNoteException('The note was not found.', 422);
    }
}
