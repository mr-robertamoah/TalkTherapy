<?php

namespace App\Actions\Contact;

use App\Actions\Action;
use App\DTOs\CreateContactDTO;
use App\Exceptions\ContactException;
use App\Models\Counsellor;

class EnsureCanUpdateContactAction extends Action
{
    public function execute(CreateContactDTO $createContactDTO)
    {
        if (
            $createContactDTO->user &&
            $createContactDTO->contact?->addedby &&
            (
                $createContactDTO->user->is($createContactDTO->contact->addedby) ||
                (
                    $createContactDTO->contact->addedby::class == Counsellor::class && 
                    $createContactDTO->contact->addedby->user->is($createContactDTO->user)
                )
            )
        ) return;

        throw new ContactException("You are not allowed to update/delete this contact.", 422);
    }
}