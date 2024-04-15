<?php

namespace App\Actions\Message;

use App\Actions\Action;
use App\DTOs\CreateMessageDTO;
use App\Exceptions\MessageException;

class EnsureIsFromUserAction extends Action
{
    public function execute(CreateMessageDTO $createMessageDTO)
    {
        if (
            $createMessageDTO->from &&
            (
                $createMessageDTO->from->is($createMessageDTO->user) ||
                $createMessageDTO->from->is($createMessageDTO->user->counsellor)
            )
        ) return;

        throw new MessageException("There is either no sender or you are not the sender of the message.", 422);
    }
}