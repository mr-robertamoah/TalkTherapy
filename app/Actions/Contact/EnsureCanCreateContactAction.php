<?php

namespace App\Actions\Contact;

use App\Actions\Action;
use App\DTOs\CreateContactDTO;
use App\Exceptions\ContactException;
use App\Models\Counsellor;

class EnsureCanCreateContactAction extends Action
{
    public function execute(CreateContactDTO $createContactDTO)
    {
        if (!$createContactDTO->user) return;

        if (
            $createContactDTO->user->is($createContactDTO->addedby) ||
            (
                $createContactDTO->addedby::class == Counsellor::class && 
                $createContactDTO->addedby?->user->is($createContactDTO->user)
            )
        ) return;

        throw new ContactException("You are not allowed to contact us with the account provided.", 422);
    }
}