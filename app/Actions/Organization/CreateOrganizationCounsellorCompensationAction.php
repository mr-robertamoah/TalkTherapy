<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationCounsellorCompensationDTO;
use App\Models\OrganizationCounsellorCompensation;

class CreateOrganizationCounsellorCompensationAction extends Action
{
    // Always inserts a new row -- never updates an existing one, so past terms stay
    // reproducible if renegotiated later. The first-ever compensation row for a `pending`
    // affiliation activates it (TT-6.4a's own rule: not active until terms exist);
    // renegotiating an already-active affiliation just adds a new row without changing status.
    public function execute(OrganizationCounsellorCompensationDTO $dto): OrganizationCounsellorCompensation
    {
        $compensation = OrganizationCounsellorCompensation::create([
            'organization_counsellor_id' => $dto->organizationCounsellor->id,
            'type' => $dto->type,
            'amount' => $dto->amount,
            'currency' => $dto->currency,
            'percentage' => $dto->percentage,
            'basis' => $dto->basis,
            'effective_from' => now(),
        ]);

        if ($dto->organizationCounsellor->isPending()) {
            $dto->organizationCounsellor->activate();
        }

        return $compensation;
    }
}
