<?php

namespace App\Actions\Message;

use App\Actions\Action;
use App\DTOs\CreateMessageDTO;
use App\Exceptions\MessageException;

class EnsureMessageExistsAction extends Action
{
    public function execute(CreateMessageDTO $createMessageDTO)
    {
        if (
            $createMessageDTO->message
        ) return;

        throw new MessageException("The message was not found.", 422);
    }
}