<?php

namespace App\Actions\Message;

use App\Actions\Action;
use App\DTOs\CreateMessageDTO;
use App\Exceptions\MessageException;
use App\Models\Discussion;
use App\Models\Session;
use App\Models\User;

class EnsureCanSendMessageToRecepientAction extends Action
{
    public function execute(CreateMessageDTO $createMessageDTO)
    {
        if (
            !$createMessageDTO->to && 
            $createMessageDTO->for::class == Session::class &&
            $createMessageDTO->for->isForTherapy()
        ) throw new MessageException("Recepient is required for a therapy session.", 422);

        if (
            (
                $createMessageDTO->for::class == Discussion::class &&
                $createMessageDTO->for->isNotParticipant(
                    $createMessageDTO->to::class == User::class 
                        ? $createMessageDTO->to->counsellor
                        : $createMessageDTO->to
                )
            ) ||
            (
                $createMessageDTO->for::class == Session::class &&
                $createMessageDTO->for->isNotParticipant(
                    $createMessageDTO->to::class == User::class 
                        ? $createMessageDTO->to
                        : $createMessageDTO->to->user
                )
            )
            
        ) throw new MessageException("You are sending the message to someone who is not participating in session/discussion.", 422);
    }
}