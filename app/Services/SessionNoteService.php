<?php

namespace App\Services;

use App\Actions\SessionNote\CreateSessionNoteAction;
use App\Actions\SessionNote\DeleteSessionNoteAction;
use App\Actions\SessionNote\EnsureCanCreateSessionNoteAction;
use App\Actions\SessionNote\EnsureCanDeleteSessionNoteAction;
use App\Actions\SessionNote\EnsureCanUpdateSessionNoteAction;
use App\Actions\SessionNote\EnsureCanViewSessionNotesAction;
use App\Actions\SessionNote\EnsureSessionExistsAction;
use App\Actions\SessionNote\EnsureSessionNoteExistsAction;
use App\Actions\SessionNote\GetOwnSessionNotesAction;
use App\Actions\SessionNote\UpdateSessionNoteAction;
use App\Actions\Suspension\EnsureCanActAsCounsellorAction;
use App\DTOs\CreateSessionNoteDTO;
use App\Models\SessionNote;
use Illuminate\Database\Eloquent\Collection;

/**
 * Class SessionNoteService
 *
 * Handles a counsellor's private, timestamped session notes -- SCRUM-21/TT-2.2. Never exposes
 * a note to anyone but the counsellor who authored it, and never over any broadcast channel.
 */
class SessionNoteService extends Service
{
    public function getOwnSessionNotes(CreateSessionNoteDTO $dto): Collection
    {
        EnsureSessionExistsAction::new()->execute($dto);

        EnsureCanViewSessionNotesAction::new()->execute($dto);

        return GetOwnSessionNotesAction::new()->execute($dto);
    }

    public function createSessionNote(CreateSessionNoteDTO $dto): SessionNote
    {
        EnsureSessionExistsAction::new()->execute($dto);

        EnsureCanCreateSessionNoteAction::new()->execute($dto);

        // SCRUM-363: notes are counsellor work; a suspended counsellor can still read theirs.
        EnsureCanActAsCounsellorAction::new()->execute($dto->counsellor ?? $dto->user?->counsellor);

        return CreateSessionNoteAction::new()->execute($dto);
    }

    public function updateSessionNote(CreateSessionNoteDTO $dto): SessionNote
    {
        EnsureSessionNoteExistsAction::new()->execute($dto);

        EnsureCanUpdateSessionNoteAction::new()->execute($dto);

        // SCRUM-363: notes are counsellor work; a suspended counsellor can still read theirs.
        EnsureCanActAsCounsellorAction::new()->execute($dto->counsellor ?? $dto->user?->counsellor);

        return UpdateSessionNoteAction::new()->execute($dto);
    }

    public function deleteSessionNote(CreateSessionNoteDTO $dto): SessionNote
    {
        EnsureSessionNoteExistsAction::new()->execute($dto);

        EnsureCanDeleteSessionNoteAction::new()->execute($dto);

        // SCRUM-363: notes are counsellor work; a suspended counsellor can still read theirs.
        EnsureCanActAsCounsellorAction::new()->execute($dto->counsellor ?? $dto->user?->counsellor);

        return DeleteSessionNoteAction::new()->execute($dto);
    }
}
