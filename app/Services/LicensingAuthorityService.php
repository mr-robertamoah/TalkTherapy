<?php

namespace App\Services;

use App\Actions\EnsureAddedbyIsValidAction;
use App\Actions\LicensingAuthority\CreateLicensingAuthorityAction;
use App\Actions\LicensingAuthority\EnsureLicensingAuthorityNameIsUniqueAction;
use App\Actions\LicensingAuthority\EnsureUserCanCreateLicensingAuthorityAction;
use App\DTOs\CreateLicensingAuthorityDTO;
use App\Enums\ConstantsEnum;
use App\Enums\PaginationEnum;
use App\Models\LicensingAuthority;

class LicensingAuthorityService extends Service
{
    public function getOtherLicensingAuthorities(?string $name)
    {
        $query = LicensingAuthority::query();

        if ($name) {
            $query->whereContains('name', (string) $name);
        }

        $query->whereNot('name', ConstantsEnum::nationalId->value);

        return $query->paginate(PaginationEnum::pagination->value);
    }

    public function createLicensingAuthority(CreateLicensingAuthorityDTO $createLicensingAuthorityDTO)
    {
        EnsureAddedbyIsValidAction::new()->execute($createLicensingAuthorityDTO);

        EnsureUserCanCreateLicensingAuthorityAction::new()->execute($createLicensingAuthorityDTO);

        EnsureLicensingAuthorityNameIsUniqueAction::new()->execute($createLicensingAuthorityDTO->name);

        $licensingAuthority = CreateLicensingAuthorityAction::new()->execute($createLicensingAuthorityDTO);

        return $licensingAuthority;
    }
}
