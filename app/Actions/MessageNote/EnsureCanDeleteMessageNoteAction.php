<?php

namespace App\Actions\MessageNote;

use App\Actions\Action;
use App\DTOs\CreateMessageNoteDTO;
use App\Exceptions\MessageNoteException;

class EnsureCanDeleteMessageNoteAction extends Action
{
    // See EnsureCanUpdateMessageNoteAction's comment -- identical rule, author-only, no window.
    public function execute(CreateMessageNoteDTO $dto)
    {
        if (! $dto->messageNote->counsellor?->is($dto->counsellor)) {
            throw new MessageNoteException('You are not allowed to delete this note.', 422);
        }
    }
}
