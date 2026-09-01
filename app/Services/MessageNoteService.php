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

        return CreateMessageNoteAction::new()->execute($dto);
    }

    public function updateMessageNote(CreateMessageNoteDTO $dto): MessageNote
    {
        EnsureMessageNoteExistsAction::new()->execute($dto);

        EnsureCanUpdateMessageNoteAction::new()->execute($dto);

        return UpdateMessageNoteAction::new()->execute($dto);
    }

    public function deleteMessageNote(CreateMessageNoteDTO $dto): MessageNote
    {
        EnsureMessageNoteExistsAction::new()->execute($dto);

        EnsureCanDeleteMessageNoteAction::new()->execute($dto);

        return DeleteMessageNoteAction::new()->execute($dto);
    }
}
