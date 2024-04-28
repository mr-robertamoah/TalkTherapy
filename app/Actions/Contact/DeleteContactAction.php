<?php

namespace App\Actions\Contact;

use App\Actions\Action;
use App\DTOs\CreateContactDTO;

class DeleteContactAction extends Action
{
    public function execute(CreateContactDTO $createContactDTO)
    {
        return $createContactDTO->contact->delete();
    }
}