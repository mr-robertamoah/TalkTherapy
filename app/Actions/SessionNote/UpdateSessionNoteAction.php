<?php

namespace App\Actions\SessionNote;

use App\Actions\Action;
use App\DTOs\CreateSessionNoteDTO;
use App\Models\SessionNote;

class UpdateSessionNoteAction extends Action
{
    public function execute(CreateSessionNoteDTO $dto): SessionNote
    {
        $dto->sessionNote->update(['content' => $dto->content]);

        return $dto->sessionNote->refresh();
    }
}
