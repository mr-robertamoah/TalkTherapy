<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationCounsellorRequestDTO;
use App\Exceptions\OrganizationException;

class EnsureUserCanApplyToOrganizationAction extends Action
{
    public function execute(OrganizationCounsellorRequestDTO $dto): void
    {
        if (is_null($dto->user) || is_null($dto->user->counsellor) || ! $dto->user->counsellor->is($dto->counsellor)) {
            throw new OrganizationException('You are not authorized to apply as this counsellor.', 403);
        }
    }
}
