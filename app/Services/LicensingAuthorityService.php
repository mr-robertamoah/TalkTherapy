<?php

namespace App\Services;

use App\Actions\EnsureAddedbyIsValidAction;
use App\Actions\LicensingAuthority\CreateLicensingAuthorityAction;
use App\Actions\LicensingAuthority\EnsureLicensingAuthorityNameIsUniqueAction;
use App\Actions\LicensingAuthority\EnsureUserCanCreateLicensingAuthorityAction;
use App\Actions\Star\CreateStarAction;
use App\DTOs\CreateLicensingAuthorityDTO;
use App\DTOs\CreateStarDTO;
use App\Enums\ConstantsEnum;
use App\Enums\PaginationEnum;
use App\Enums\StarTypeEnum;
use App\Models\LicensingAuthority;
use App\Models\User;

class LicensingAuthorityService extends Service
{
    public function getOtherLicensingAuthorities(string|null $name)
    {
        $query = LicensingAuthority::query();

        if ($name) $query->where('name', 'LIKE', "%{$name}%");

        $query->whereNot('name', ConstantsEnum::nationalId->value);

        return $query->paginate(PaginationEnum::pagination->value);
    }

    public function createLicensingAuthority(CreateLicensingAuthorityDTO $createLicensingAuthorityDTO) {
        EnsureAddedbyIsValidAction::new()->execute($createLicensingAuthorityDTO);
        
        EnsureUserCanCreateLicensingAuthorityAction::new()->execute($createLicensingAuthorityDTO);
        
        EnsureLicensingAuthorityNameIsUniqueAction::new()->execute($createLicensingAuthorityDTO->name);

        $licensingAuthority = CreateLicensingAuthorityAction::new()->execute($createLicensingAuthorityDTO);

        CreateStarAction::new()->execute(
            CreateStarDTO::fromArray([
                'starredby' => null,
                'starred' => $createLicensingAuthorityDTO->addedby
                    ? (
                        $createLicensingAuthorityDTO->addedby::class == User::class
                            ? $createLicensingAuthorityDTO->addedby
                            : $createLicensingAuthorityDTO->addedby->user
                    ) 
                    : $createLicensingAuthorityDTO->user,
                'starreable' => $licensingAuthority,
                'type' => StarTypeEnum::contribution->value,
            ])
        );

        return $licensingAuthority;
    }
}