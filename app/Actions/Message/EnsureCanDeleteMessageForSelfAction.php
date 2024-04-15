<?php

namespace App\Actions\Message;

use App\Actions\Action;
use App\DTOs\CreateMessageDTO;
use App\Exceptions\MessageException;

class EnsureCanDeleteMessageForSelfAction extends Action
{
    public function execute(CreateMessageDTO $createMessageDTO)
    {
        if (
            $createMessageDTO->message->for->isParticipant($createMessageDTO->user)
        ) return;

        throw new MessageException("You cannot delete the message for yourself since you are not a participant.", 422);
    }
}