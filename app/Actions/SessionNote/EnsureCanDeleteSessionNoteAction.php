<?php

namespace App\Actions\SessionNote;

use App\Actions\Action;
use App\DTOs\CreateSessionNoteDTO;
use App\Exceptions\SessionNoteException;
use App\Traits\GuardsPrivateNoteEditWindow;

class EnsureCanDeleteSessionNoteAction extends Action
{
    use GuardsPrivateNoteEditWindow;

    // See EnsureCanUpdateSessionNoteAction's comment -- identical rule, author-only + live/grace
    // window, no isAdmin() bypass.
    public function execute(CreateSessionNoteDTO $dto)
    {
        if (! $dto->sessionNote->counsellor?->is($dto->counsellor)) {
            throw new SessionNoteException('You are not allowed to delete this note.', 422);
        }

        if (! $this->sessionAcceptsNoteEdits($dto->sessionNote->session)) {
            throw new SessionNoteException('This note can no longer be deleted.', 422);
        }
    }
}
