<?php

namespace App\Actions\SessionNote;

use App\Actions\Action;
use App\DTOs\CreateSessionNoteDTO;
use App\Models\SessionNote;
use Illuminate\Database\Eloquent\Collection;

class GetOwnSessionNotesAction extends Action
{
    // The counsellor_id scope here is the single most important line in this ticket (SCRUM-197
    // AC7) -- it's what stops a co-counsellor on a shared GroupTherapy session from ever seeing
    // another counsellor's notes. Never widen this to "any counsellor on the session".
    public function execute(CreateSessionNoteDTO $dto): Collection
    {
        return SessionNote::query()
            ->where('session_id', $dto->session->id)
            ->where('counsellor_id', $dto->counsellor->id)
            ->latest()
            ->get();
    }
}
