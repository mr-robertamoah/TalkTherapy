<?php

namespace App\Actions\MessageNote;

use App\Actions\Action;
use App\DTOs\CreateMessageNoteDTO;
use App\Models\MessageNote;

class UpdateMessageNoteAction extends Action
{
    public function execute(CreateMessageNoteDTO $dto): MessageNote
    {
        $dto->messageNote->update(['content' => $dto->content]);

        return $dto->messageNote->refresh();
    }
}
