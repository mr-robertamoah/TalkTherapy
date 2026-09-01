<?php

namespace App\Actions\MessageNote;

use App\Actions\Action;
use App\DTOs\CreateMessageNoteDTO;
use App\Exceptions\MessageNoteException;
use App\Models\MessageNote;
use App\Traits\ChecksCounsellorIsMessageCounsellor;

class EnsureCanCreateMessageNoteAction extends Action
{
    use ChecksCounsellorIsMessageCounsellor;

    // No isAdmin() bypass -- see EnsureCanViewMessageNoteAction's comment.
    public function execute(CreateMessageNoteDTO $dto)
    {
        if (! $this->counsellorIsMessageCounsellor($dto->message, $dto->counsellor)) {
            throw new MessageNoteException('You are not allowed to annotate this message.', 422);
        }

        // Max one note per counsellor per message (product decision, SCRUM-22/TT-2.3) -- checked
        // here for a clean 422 rather than letting the DB's unique constraint surface as a raw
        // integrity-violation exception.
        $alreadyExists = MessageNote::query()
            ->where('message_id', $dto->message->id)
            ->where('counsellor_id', $dto->counsellor->id)
            ->exists();

        if ($alreadyExists) {
            throw new MessageNoteException('You have already added a note to this message. Edit your existing note instead.', 422);
        }
    }
}
