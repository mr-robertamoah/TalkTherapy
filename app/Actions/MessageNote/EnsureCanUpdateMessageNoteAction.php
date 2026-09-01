<?php

namespace App\Actions\MessageNote;

use App\Actions\Action;
use App\DTOs\CreateMessageNoteDTO;
use App\Exceptions\MessageNoteException;

class EnsureCanUpdateMessageNoteAction extends Action
{
    // Author-only, no isAdmin() bypass, no time-based edit window -- see decision-log.md
    // ("SCRUM-22 (TT-2.3): message-note editability diverges from the documented reuse plan")
    // for why this deliberately does not reuse GuardsPrivateNoteEditWindow: a message-level note
    // is often reviewed well after the session it's attached to has ended, and Discussion (one of
    // Message::for's two possible targets) has no ended_at concept at all to gate on. If the
    // note's counsellor_id has since been nulled (its author's Counsellor row was force-deleted --
    // see the message_notes migration's nullOnDelete comment), counsellor() resolves to null and
    // nobody can update it any more, which is the correct behaviour for an orphaned note.
    public function execute(CreateMessageNoteDTO $dto)
    {
        if (! $dto->messageNote->counsellor?->is($dto->counsellor)) {
            throw new MessageNoteException('You are not allowed to update this note.', 422);
        }
    }
}
