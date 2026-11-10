<?php

namespace App\Actions\SessionNote;

use App\Actions\Action;
use App\DTOs\CreateSessionNoteDTO;
use App\Exceptions\SessionNoteException;
use App\Traits\GuardsPrivateNoteEditWindow;

class EnsureCanCreateSessionNoteAction extends Action
{
    use GuardsPrivateNoteEditWindow;

    // Deliberately no isAdmin() bypass -- unlike every other Session/Therapy Ensure*Action in
    // this codebase, session notes are never admin-accessible (product decision, 2026-09-01):
    // they're a counsellor's private clinical observations, not operational state.
    public function execute(CreateSessionNoteDTO $dto)
    {
        if (! $dto->counsellor || ! $dto->session->for?->isCounsellor($dto->counsellor)) {
            throw new SessionNoteException('You are not allowed to add notes to this session.', 422);
        }

        if (! $this->sessionAcceptsNewNotes($dto->session)) {
            throw new SessionNoteException('Notes can only be added while the session is in progress.', 422);
        }
    }
}
