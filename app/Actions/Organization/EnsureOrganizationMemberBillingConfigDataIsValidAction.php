<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationMemberBillingConfigDTO;
use App\Enums\OrganizationMemberBillingModeEnum;
use App\Exceptions\OrganizationException;

class EnsureOrganizationMemberBillingConfigDataIsValidAction extends Action
{
    // Symmetric across both modes from the start (SCRUM-122 found the hard way that an
    // asymmetric version of this check -- only guarding one branch against the other's
    // leftover fields -- is a real bug, not just a style nit).
    public function execute(OrganizationMemberBillingConfigDTO $dto): void
    {
        if (is_null($dto->includeGroupTherapies)) {
            throw new OrganizationException('A billing configuration must specify whether it includes group therapies.', 422);
        }

        if ($dto->mode === OrganizationMemberBillingModeEnum::payPerUse->value) {
            if (is_null($dto->per)) {
                throw new OrganizationException('A pay-per-use billing configuration requires a per-session or per-therapy granularity.', 422);
            }

            return;
        }

        if ($dto->mode === OrganizationMemberBillingModeEnum::retainer->value) {
            if (! is_null($dto->per)) {
                throw new OrganizationException('A retainer billing configuration cannot carry a per-session or per-therapy granularity.', 422);
            }

            return;
        }

        throw new OrganizationException('Billing mode must be retainer or pay-per-use.', 422);
    }
}
