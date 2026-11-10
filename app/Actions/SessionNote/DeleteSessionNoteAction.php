<?php

namespace App\Actions\SessionNote;

use App\Actions\Action;
use App\DTOs\CreateSessionNoteDTO;
use App\Models\SessionNote;

class DeleteSessionNoteAction extends Action
{
    public function execute(CreateSessionNoteDTO $dto): SessionNote
    {
        $dto->sessionNote->delete();

        return $dto->sessionNote;
    }
}
