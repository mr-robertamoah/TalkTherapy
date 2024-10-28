<?php

namespace App\Actions\Message;

use App\Actions\Action;
use App\DTOs\CreateMessageDTO;
use App\Exceptions\MessageException;
use App\Models\Discussion;
use App\Models\Session;
use App\Models\User;

class EnsureMessageDataIsValidAction extends Action
{
    public function execute(CreateMessageDTO $createMessageDTO, bool $isUpdate = false) : void  
    {
        // TODO ensure that deletefiles are files for the message
        // TODO ensure that deleting files does not leave message with no content and files
        if (
            $createMessageDTO->content ||
            $createMessageDTO->files ||
            ($isUpdate && $createMessageDTO->deletedFiles)
        ) return;
        
        throw new MessageException("There is not sufficient information to create a message. There should be content or files, at least.", 422);    
    }
}