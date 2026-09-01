<?php

namespace App\Actions\SessionNote;

use App\Actions\Action;
use App\DTOs\CreateSessionNoteDTO;
use App\Exceptions\SessionNoteException;

class EnsureCanViewSessionNotesAction extends Action
{
    // Only gates "is this counsellor assigned to the session at all" -- the actual per-author
    // isolation (never returning another co-counsellor's notes on a shared GroupTherapy session)
    // is enforced by GetOwnSessionNotesAction's own counsellor_id scope, not here. No isAdmin()
    // bypass -- see EnsureCanCreateSessionNoteAction's comment.
    public function execute(CreateSessionNoteDTO $dto)
    {
        if (! $dto->counsellor || ! $dto->session->for?->isCounsellor($dto->counsellor)) {
            throw new SessionNoteException('You are not allowed to view notes for this session.', 422);
        }
    }
}
