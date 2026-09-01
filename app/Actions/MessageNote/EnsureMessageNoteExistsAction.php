<?php

namespace App\Actions\MessageNote;

use App\Actions\Action;
use App\DTOs\CreateMessageNoteDTO;
use App\Exceptions\MessageNoteException;

class EnsureMessageNoteExistsAction extends Action
{
    public function execute(CreateMessageNoteDTO $dto)
    {
        if ($dto->messageNote) {
            return;
        }

        throw new MessageNoteException('The note was not found.', 422);
    }
}
