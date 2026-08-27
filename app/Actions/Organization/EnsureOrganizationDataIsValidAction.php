<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationDTO;
use App\Exceptions\OrganizationException;

class EnsureOrganizationDataIsValidAction extends Action
{
    // Defense-in-depth on top of the DB-level CHECK constraint -- this catches the bad
    // request before a query even runs, and gives a clean validation-style message instead
    // of surfacing a raw constraint-violation error.
    public function execute(OrganizationDTO $dto): void
    {
        $organization = $dto->organization;

        $isProvider = $dto->isProvider ?? $organization?->is_provider ?? false;
        $isConsumer = $dto->isConsumer ?? $organization?->is_consumer ?? false;

        if (! $isProvider && ! $isConsumer) {
            throw new OrganizationException('An organization must be a provider, a consumer, or both.', 422);
        }
    }
}
