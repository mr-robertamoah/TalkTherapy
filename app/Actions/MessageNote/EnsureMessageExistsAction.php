<?php

namespace App\Actions\MessageNote;

use App\Actions\Action;
use App\DTOs\CreateMessageNoteDTO;
use App\Exceptions\MessageNoteException;

class EnsureMessageExistsAction extends Action
{
    public function execute(CreateMessageNoteDTO $dto)
    {
        if ($dto->message) {
            return;
        }

        throw new MessageNoteException('Message was not found.', 422);
    }
}
