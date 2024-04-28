<?php

namespace App\Actions\Contact;

use App\Actions\Action;
use App\DTOs\CreateContactDTO;
use App\Enums\ContactTypeEnum;
use App\Exceptions\ContactException;

class EnsureContactDataIsValidAction extends Action
{
    public function execute(CreateContactDTO $createContactDTO, string $action = 'create')
    {
        if (
            $this->canCreate($createContactDTO) ||
            ($action == 'update' && $this->canUpdate($createContactDTO))
        ) return;

        throw new ContactException("You have not provided enough data to {$action} contact.", 422);
    }

    private function canCreate(CreateContactDTO $createContactDTO)
    {
        if (!in_array($createContactDTO->type, ContactTypeEnum::values()))
            throw new ContactException("An invalid contact type was provided.", 422);
        
        return ($createContactDTO->addedby || ($createContactDTO->name && $createContactDTO->email)) && 
            $createContactDTO->type &&
            $createContactDTO->content;
    }

    private function canUpdate(CreateContactDTO $createContactDTO)
    {
        if ($createContactDTO->type && !in_array($createContactDTO->type, ContactTypeEnum::values()))
            throw new ContactException("An invalid contact type was provided.", 422);

        return $createContactDTO->name ||
            $createContactDTO->type ||
            $createContactDTO->organisation ||
            $createContactDTO->email ||
            $createContactDTO->content;
    }
}