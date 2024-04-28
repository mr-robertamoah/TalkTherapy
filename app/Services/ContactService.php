<?php

namespace App\Services;

use App\Actions\Contact\CreateContactAction;
use App\Actions\Contact\DeleteContactAction;
use App\Actions\Contact\EnsureCanCreateContactAction;
use App\Actions\Contact\EnsureCanUpdateContactAction;
use App\Actions\Contact\EnsureContactDataIsValidAction;
use App\Actions\Contact\EnsureContactExistsAction;
use App\Actions\Contact\UpdateContactAction;
use App\Actions\EnsureAddedbyIsValidAction;
use App\DTOs\CreateContactDTO;
use App\Enums\PaginationEnum;
use App\Models\Contact;

class ContactService extends Service
{
    public function createContact(CreateContactDTO $createContactDTO)
    {
        EnsureAddedbyIsValidAction::new()->execute(
            $createContactDTO,
            "You are not allowed to use the account to add a contact."
        );

        EnsureCanCreateContactAction::new()->execute($createContactDTO);

        EnsureContactDataIsValidAction::new()->execute($createContactDTO);

        $contact = CreateContactAction::new()->execute($createContactDTO);

        // AppService::new()->alertAdminWithContact($contact);

        return $contact;
    }

    public function updateContact(CreateContactDTO $createContactDTO)
    {
        EnsureContactExistsAction::new()->execute($createContactDTO);

        EnsureCanUpdateContactAction::new()->execute($createContactDTO);

        EnsureContactDataIsValidAction::new()->execute($createContactDTO, 'update');

        return UpdateContactAction::new()->execute($createContactDTO);
    }

    public function deleteContact(CreateContactDTO $createContactDTO)
    {
        EnsureContactExistsAction::new()->execute($createContactDTO);

        EnsureCanUpdateContactAction::new()->execute($createContactDTO);

        return DeleteContactAction::new()->execute($createContactDTO);
    }

    public function getContacts(CreateContactDTO $createContactDTO)
    {
        $query = Contact::query();

        $query->when($createContactDTO->addedby, function ($query) use ($createContactDTO) {
            $query->whereAddedby($createContactDTO->addedby);
        });

        $query
            ->whereLike($createContactDTO->like)
            ->whereName($createContactDTO->name)
            ->whereOrganisation($createContactDTO->organisation)
            ->orderByDesc('created_at');

        return $query->paginate(PaginationEnum::preferencesPagination->value);
    }
}