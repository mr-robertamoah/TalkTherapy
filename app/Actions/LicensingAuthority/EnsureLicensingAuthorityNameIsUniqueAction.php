<?php

namespace App\Actions\LicensingAuthority;

use App\Actions\Action;
use App\Exceptions\LicensingAuthorityNameIsNotUniqueException;
use App\Models\LicensingAuthority;

class EnsureLicensingAuthorityNameIsUniqueAction extends Action
{
    public function execute(String $name) {
        if (!LicensingAuthority::query()->where('name', $name)->exists()) return;

        throw new LicensingAuthorityNameIsNotUniqueException('Please use a licensing authority name that has not already been used.', 422);
    }
}