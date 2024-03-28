<?php

namespace App\Actions\LicensingAuthority;

use App\Actions\Action;
use App\DTOs\CreateLicensingAuthorityDTO;

class CreateLicensingAuthorityAction extends Action
{
    public function execute(CreateLicensingAuthorityDTO $createLicensingAuthorityDTO) {
        $addedby = $createLicensingAuthorityDTO->addedby
            ? $createLicensingAuthorityDTO->addedby
            : $createLicensingAuthorityDTO->user;

        return $addedby->addedLicensingAuthorities()->create([
            ...$createLicensingAuthorityDTO->getData(true),
            'license_type' => $createLicensingAuthorityDTO->licenseType
        ]);
    }
}