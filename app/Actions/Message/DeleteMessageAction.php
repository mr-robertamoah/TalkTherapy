<?php

namespace App\Actions\Message;

use App\Actions\Action;
use App\DTOs\CreateMessageDTO;
use App\Models\Message;

class DeleteMessageAction extends Action
{
    public function execute(CreateMessageDTO $createMessageDTO)
    {
        Message::withoutTimestamps(function () use ($createMessageDTO) {
            $createMessageDTO->message->delete();
        });

        return $createMessageDTO->message->refresh();
    }
}