<?php

namespace App\Actions\Contact;

use App\Actions\Action;
use App\DTOs\CreateContactDTO;

class UpdateContactAction extends Action
{
    private array $data = [];

    public function execute(CreateContactDTO $createContactDTO)
    {
        $this->setData($createContactDTO);

        $createContactDTO->contact->update($this->data);

        return $createContactDTO->contact->refresh();
    }

    private function setData(CreateContactDTO $createContactDTO)
    {
        if ($createContactDTO->content && $createContactDTO->content !== $createContactDTO->contact->content)
            $this->data['content'] = $createContactDTO->content;

        if ($createContactDTO->name && $createContactDTO->name !== $createContactDTO->contact->name)
            $this->data['name'] = $createContactDTO->name;

        if ($createContactDTO->organisation && $createContactDTO->organisation !== $createContactDTO->contact->organisation)
            $this->data['organisation'] = $createContactDTO->organisation;

        if ($createContactDTO->email && $createContactDTO->email !== $createContactDTO->contact->email)
            $this->data['email'] = $createContactDTO->email;

        if ($createContactDTO->type && $createContactDTO->type !== $createContactDTO->contact->type)
            $this->data['type'] = $createContactDTO->type;
    }
}