<?php

namespace App\Actions\Message;

use App\Actions\Action;
use App\DTOs\CreateMessageDTO;
use App\Exceptions\MessageException;
use App\Models\Discussion;

class EnsureCanDeleteMessageForSelfAction extends Action
{
    public function execute(CreateMessageDTO $createMessageDTO)
    {
        $participant = $createMessageDTO->user;

        if ($createMessageDTO->message->for::class == Discussion::class)
            $participant = $createMessageDTO->user?->counsellor;

        if (
            $createMessageDTO->message->for->isParticipant($participant)
        ) return;

        throw new MessageException("You cannot delete the message for yourself since you are not a participant.", 422);
    }
}