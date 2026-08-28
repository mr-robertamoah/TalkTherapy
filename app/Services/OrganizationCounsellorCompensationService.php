<?php

namespace App\Services;

use App\Actions\Organization\EnsureNoPendingOrganizationCounsellorCompensationRequestAction;
use App\Actions\Organization\EnsureOrganizationCounsellorCompensationDataIsValidAction;
use App\Actions\Organization\EnsureOrganizationCounsellorExistsAction;
use App\Actions\Organization\EnsureUserCanSetOrganizationCounsellorCompensationAction;
use App\Actions\Organization\EnsureUserCanViewOrganizationCounsellorCompensationsAction;
use App\Actions\Organization\ProposeOrganizationCounsellorCompensationChangeAction;
use App\DTOs\OrganizationCounsellorCompensationDTO;
use App\Models\Request;
use Illuminate\Database\Eloquent\Collection;

class OrganizationCounsellorCompensationService extends Service
{
    // SCRUM-146 (TT-6.4c): was setCompensation() -- an org admin's write was previously
    // unilateral and immediately effective. Now creates a proposal the counsellor must accept
    // (SCRUM-147) before organization_counsellor_compensations ever sees a new row.
    public function proposeCompensationChange(OrganizationCounsellorCompensationDTO $dto): Request
    {
        EnsureOrganizationCounsellorExistsAction::new()->execute($dto);

        EnsureUserCanSetOrganizationCounsellorCompensationAction::new()->execute($dto);

        EnsureOrganizationCounsellorCompensationDataIsValidAction::new()->execute($dto);

        EnsureNoPendingOrganizationCounsellorCompensationRequestAction::new()->execute($dto);

        return ProposeOrganizationCounsellorCompensationChangeAction::new()->execute($dto);
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
