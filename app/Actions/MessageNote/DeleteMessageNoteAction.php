<?php

namespace App\Actions\MessageNote;

use App\Actions\Action;
use App\DTOs\CreateMessageNoteDTO;
use App\Models\MessageNote;

class DeleteMessageNoteAction extends Action
{
    public function execute(CreateMessageNoteDTO $dto): MessageNote
    {
        $dto->messageNote->delete();

        return $dto->messageNote;
    }
}
