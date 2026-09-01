<?php

namespace App\Actions\MessageNote;

use App\Actions\Action;
use App\DTOs\CreateMessageNoteDTO;
use App\Models\MessageNote;

class CreateMessageNoteAction extends Action
{
    // The unique index on (message_id, counsellor_id) doesn't distinguish trashed rows from live
    // ones -- a soft-deleted note still occupies that key. EnsureCanCreateMessageNoteAction's own
    // duplicate check only sees live rows (Eloquent's default soft-delete scope), so a
    // create-after-delete would otherwise pass that check and then hit a raw unique-constraint
    // QueryException here (security-engineer finding, SCRUM-202). Restoring the trashed row with
    // the new content, instead of inserting a second one, is also the correct semantics: at most
    // one note has ever existed for a given (message, counsellor) pair, live or trashed.
    public function execute(CreateMessageNoteDTO $dto): MessageNote
    {
        $trashed = MessageNote::onlyTrashed()
            ->where('message_id', $dto->message->id)
            ->where('counsellor_id', $dto->counsellor->id)
            ->first();

        if ($trashed) {
            $trashed->restore();
            $trashed->update(['content' => $dto->content]);

            return $trashed->fresh();
        }

        return MessageNote::create([
            'content' => $dto->content,
            'message_id' => $dto->message->id,
            'counsellor_id' => $dto->counsellor->id,
        ]);
    }
}
