<?php

namespace App\Actions\SessionNote;

use App\Actions\Action;
use App\DTOs\CreateSessionNoteDTO;
use App\Models\SessionNote;

class CreateSessionNoteAction extends Action
{
    public function execute(CreateSessionNoteDTO $dto): SessionNote
    {
        return SessionNote::create([
            'content' => $dto->content,
            'session_id' => $dto->session->id,
            'counsellor_id' => $dto->counsellor->id,
        ]);
    }
}
