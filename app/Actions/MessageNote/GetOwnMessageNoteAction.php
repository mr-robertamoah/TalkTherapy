<?php

namespace App\Actions\MessageNote;

use App\Actions\Action;
use App\DTOs\CreateMessageNoteDTO;
use App\Models\MessageNote;

class GetOwnMessageNoteAction extends Action
{
    // The counsellor_id scope here is the isolation guarantee that stops a co-counsellor on a
    // shared GroupTherapy/Discussion from ever seeing another counsellor's note on the same
    // message -- never widen this to "any counsellor on the message's context". Nullable return:
    // cardinality is at most one note per (message, counsellor), and "no note yet" is a normal,
    // non-error state.
    public function execute(CreateMessageNoteDTO $dto): ?MessageNote
    {
        return MessageNote::query()
            ->where('message_id', $dto->message->id)
            ->where('counsellor_id', $dto->counsellor->id)
            ->first();
    }
}
