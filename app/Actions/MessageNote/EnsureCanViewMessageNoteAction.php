<?php

namespace App\Actions\MessageNote;

use App\Actions\Action;
use App\DTOs\CreateMessageNoteDTO;
use App\Exceptions\MessageNoteException;
use App\Traits\ChecksCounsellorIsMessageCounsellor;

class EnsureCanViewMessageNoteAction extends Action
{
    use ChecksCounsellorIsMessageCounsellor;

    // No isAdmin() bypass -- message notes are a counsellor's private clinical observations, not
    // operational state, same rule as SessionNote (product decision, 2026-09-01).
    public function execute(CreateMessageNoteDTO $dto)
    {
        if (! $this->counsellorIsMessageCounsellor($dto->message, $dto->counsellor)) {
            throw new MessageNoteException('You are not allowed to view notes for this message.', 422);
        }
    }
}
