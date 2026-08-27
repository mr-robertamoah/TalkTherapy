<?php

namespace App\Services;

use App\Actions\Organization\CreateOrganizationCounsellorCompensationAction;
use App\Actions\Organization\EnsureOrganizationCounsellorCompensationDataIsValidAction;
use App\Actions\Organization\EnsureOrganizationCounsellorExistsAction;
use App\Actions\Organization\EnsureUserCanSetOrganizationCounsellorCompensationAction;
use App\Actions\Organization\EnsureUserCanViewOrganizationCounsellorCompensationsAction;
use App\DTOs\OrganizationCounsellorCompensationDTO;
use App\Models\OrganizationCounsellorCompensation;
use Illuminate\Database\Eloquent\Collection;

class OrganizationCounsellorCompensationService extends Service
{
    public function setCompensation(OrganizationCounsellorCompensationDTO $dto): OrganizationCounsellorCompensation
    {
        EnsureOrganizationCounsellorExistsAction::new()->execute($dto);

        EnsureUserCanSetOrganizationCounsellorCompensationAction::new()->execute($dto);

        EnsureOrganizationCounsellorCompensationDataIsValidAction::new()->execute($dto);

        return CreateOrganizationCounsellorCompensationAction::new()->execute($dto);
    }

    public function getCompensations(OrganizationCounsellorCompensationDTO $dto): Collection
    {
        EnsureOrganizationCounsellorExistsAction::new()->execute($dto);

        EnsureUserCanViewOrganizationCounsellorCompensationsAction::new()->execute($dto);

        return $dto->organizationCounsellor->compensations()
            ->with('setBy')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();
    }
}
