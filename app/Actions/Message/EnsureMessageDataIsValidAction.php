<?php

namespace App\Actions\Message;

use App\Actions\Action;
use App\DTOs\CreateMessageDTO;
use App\Exceptions\MessageException;
use App\Models\User;

class EnsureMessageDataIsValidAction extends Action
{
    public function execute(CreateMessageDTO $createMessageDTO)
    {
        if (
            !$createMessageDTO->for
        ) throw new MessageException("A message must either be for a discussion or session but non was provided.", 422);

        if (
            $createMessageDTO->to &&
            $createMessageDTO->for->isNotParticipant(
                $createMessageDTO->to::class == User::class 
                    ? $createMessageDTO->to
                    : $createMessageDTO->to->user
            )
        ) throw new MessageException("You are sending the message to someone who is not authorized.", 422);    

        if (
            $createMessageDTO->content ||
            $createMessageDTO->files
        ) return;
        
        throw new MessageException("There is not sufficient information to create a message. There should be content or files, at least.", 422);    
    }
}