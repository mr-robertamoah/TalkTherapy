<?php

namespace App\Actions\Contact;

use App\Actions\Action;
use App\DTOs\CreateContactDTO;
use App\Exceptions\ContactException;

class EnsureContactExistsAction extends Action
{
    public function execute(CreateContactDTO $createContactDTO)
    {
        if (
            $createContactDTO->contact
        ) return;

        throw new ContactException("You cannot perform this action because contact was not found.", 422);
    }
}