<?php

namespace App\Actions\LicensingAuthority;

use App\Actions\Action;
use App\DTOs\CreateLicensingAuthorityDTO;
use App\Exceptions\UserCanCreateLicensingAuthorityException;
use App\Models\Counsellor;

class EnsureUserCanCreateLicensingAuthorityAction extends Action
{
    public function execute(CreateLicensingAuthorityDTO $createLicensingAuthorityDTO) {
        if (
            (
                $createLicensingAuthorityDTO->addedby &&
                $createLicensingAuthorityDTO->addedby::class == Counsellor::class &&
                $createLicensingAuthorityDTO->addedby->user->is($createLicensingAuthorityDTO->user)    
            ) ||
            $createLicensingAuthorityDTO->user?->isAdmin()
        ) return;

        throw new UserCanCreateLicensingAuthorityException('You are not allowed to create a licensing authority.', 422);
    }
}