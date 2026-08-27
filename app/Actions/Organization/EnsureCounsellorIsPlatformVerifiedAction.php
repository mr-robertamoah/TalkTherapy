<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Exceptions\OrganizationException;
use App\Models\Counsellor;

class EnsureCounsellorIsPlatformVerifiedAction extends Action
{
    public function execute(Counsellor $counsellor): void
    {
        if (! $counsellor->isVerified()) {
            throw new OrganizationException('Only a platform-verified counsellor can be affiliated with an organization.', 422);
        }
    }
}
