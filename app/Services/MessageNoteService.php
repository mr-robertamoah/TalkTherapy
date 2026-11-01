<?php

namespace App\Services;

use App\Actions\MessageNote\CreateMessageNoteAction;
use App\Actions\MessageNote\DeleteMessageNoteAction;
use App\Actions\MessageNote\EnsureCanCreateMessageNoteAction;
use App\Actions\MessageNote\EnsureCanDeleteMessageNoteAction;
use App\Actions\MessageNote\EnsureCanUpdateMessageNoteAction;
use App\Actions\MessageNote\EnsureCanViewMessageNoteAction;
use App\Actions\MessageNote\EnsureMessageExistsAction;
use App\Actions\MessageNote\EnsureMessageNoteExistsAction;
use App\Actions\MessageNote\GetOwnMessageNoteAction;
use App\Actions\MessageNote\UpdateMessageNoteAction;
use App\Actions\Suspension\EnsureCanActAsCounsellorAction;
use App\DTOs\CreateMessageNoteDTO;
use App\Models\MessageNote;

/**
 * Class MessageNoteService
 *
 * Handles a counsellor's private, timestamped notes on a specific chat Message -- SCRUM-22/TT-2.3.
 * Never exposes a note to anyone but the counsellor who authored it, and never over any broadcast
 * channel.
 */
class MessageNoteService extends Service
{
    public function getOwnMessageNote(CreateMessageNoteDTO $dto): ?MessageNote
    {
        EnsureMessageExistsAction::new()->execute($dto);

        EnsureCanViewMessageNoteAction::new()->execute($dto);

        return GetOwnMessageNoteAction::new()->execute($dto);
    }

    public function createMessageNote(CreateMessageNoteDTO $dto): MessageNote
    {
        EnsureMessageExistsAction::new()->execute($dto);

        EnsureCanCreateMessageNoteAction::new()->execute($dto);

        // SCRUM-363: notes are counsellor work; a suspended counsellor can still read theirs.
        EnsureCanActAsCounsellorAction::new()->execute($dto->counsellor ?? $dto->user?->counsellor);

        return CreateMessageNoteAction::new()->execute($dto);
    }

    public function updateMessageNote(CreateMessageNoteDTO $dto): MessageNote
    {
        EnsureMessageNoteExistsAction::new()->execute($dto);

        EnsureCanUpdateMessageNoteAction::new()->execute($dto);

        // SCRUM-363: notes are counsellor work; a suspended counsellor can still read theirs.
        EnsureCanActAsCounsellorAction::new()->execute($dto->counsellor ?? $dto->user?->counsellor);

        return UpdateMessageNoteAction::new()->execute($dto);
    }

    public function deleteMessageNote(CreateMessageNoteDTO $dto): MessageNote
    {
        EnsureMessageNoteExistsAction::new()->execute($dto);

        EnsureCanDeleteMessageNoteAction::new()->execute($dto);

        // SCRUM-363: notes are counsellor work; a suspended counsellor can still read theirs.
        EnsureCanActAsCounsellorAction::new()->execute($dto->counsellor ?? $dto->user?->counsellor);

        return DeleteMessageNoteAction::new()->execute($dto);
    }
}
