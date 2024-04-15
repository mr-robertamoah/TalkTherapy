<?php

namespace App\Actions\Message;

use App\Actions\Action;
use App\DTOs\CreateMessageDTO;
use App\Exceptions\MessageException;
use App\Models\Discussion;

class EnsureCanSendMessageToForAction extends Action
{
    public function execute(CreateMessageDTO $createMessageDTO)
    {
        if (!$createMessageDTO->for)
            throw new MessageException("A message has to be created for a discussion or session.", 422);
        
        if ($createMessageDTO->for::class == Discussion::class)
            return $this->validateForDiscussion($createMessageDTO);

        $this->validateForSession($createMessageDTO);
    }

    public function validateForSession(CreateMessageDTO $createMessageDTO)
    {
        if (
            $createMessageDTO->for->for->isParticipant($createMessageDTO->user)
        ) return;

        throw new MessageException("You are not allowed to create a message for this session.", 422);
    }

    public function validateForDiscussion(CreateMessageDTO $createMessageDTO)
    {
        // TODO
    }
}