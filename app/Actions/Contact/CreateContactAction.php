<?php

namespace App\Actions\Contact;

use App\Actions\Action;
use App\DTOs\CreateContactDTO;
use App\Models\Contact;

class CreateContactAction extends Action
{
    public function execute(CreateContactDTO $createContactDTO)
    {
        $contact = Contact::create([
            'content' => $createContactDTO->content,
            'name' => $createContactDTO->name,
            'organisation' => $createContactDTO->organisation,
            'email' => $createContactDTO->email,
            'type' => $createContactDTO->type,
        ]);

        if ($createContactDTO->addedby) {

            $contact->addedby()->associate($createContactDTO->addedby);
            $contact->save();
        }

        return $contact->refresh();
    }
}