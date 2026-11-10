<?php

namespace App\Actions\SessionNote;

use App\Actions\Action;
use App\DTOs\CreateSessionNoteDTO;
use App\Exceptions\SessionNoteException;
use App\Traits\GuardsPrivateNoteEditWindow;

class EnsureCanUpdateSessionNoteAction extends Action
{
    use GuardsPrivateNoteEditWindow;

    // Author-only, no isAdmin() bypass -- see EnsureCanCreateSessionNoteAction's comment. If the
    // note's counsellor_id has since been nulled (its author's Counsellor row was force-deleted
    // -- see the session_notes migration's nullOnDelete comment), counsellor() resolves to null
    // and nobody can update it any more, which is the correct behaviour for an orphaned note.
    // Deliberately NOT re-checking current session assignment here (unlike
    // EnsureCanViewSessionNotesAction) -- a counsellor removed from the session after authoring
    // a note still retains control of their own past note; being an ex-participant shouldn't
    // strip authorship the way it correctly gates visibility of the session's live surface.
    public function execute(CreateSessionNoteDTO $dto)
    {
        if (! $dto->sessionNote->counsellor?->is($dto->counsellor)) {
            throw new SessionNoteException('You are not allowed to update this note.', 422);
        }

        if (! $this->sessionAcceptsNoteEdits($dto->sessionNote->session)) {
            throw new SessionNoteException('This note can no longer be edited.', 422);
        }
    }
}
