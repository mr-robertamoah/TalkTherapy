<?php

namespace App\Actions\Message;

use App\Actions\Action;
use App\DTOs\CreateMessageDTO;
use App\Exceptions\MessageException;
use App\Models\Counsellor;

class EnsureCanUpdateMessageAction extends Action
{
    public function execute(CreateMessageDTO $createMessageDTO)
    {
        if (
            $createMessageDTO->user?->isAdmin() ||
            (
                $createMessageDTO->message->from->is($createMessageDTO->user) ||
                $createMessageDTO->message->from->is($createMessageDTO->user->counsellor)
            )
        ) return;

        throw new MessageException("You are not authorized to update this message.", 422);
    }
}