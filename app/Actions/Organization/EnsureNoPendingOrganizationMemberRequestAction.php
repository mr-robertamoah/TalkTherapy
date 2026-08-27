<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationMemberRequestDTO;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Exceptions\OrganizationException;
use App\Models\Request;

class EnsureNoPendingOrganizationMemberRequestAction extends Action
{
    // Checked in both directions -- mirrors EnsureNoPendingOrganizationCounsellorRequestAction.
    public function execute(OrganizationMemberRequestDTO $dto): void
    {
        $exists = Request::query()
            ->whereIn('type', [
                RequestTypeEnum::organizationMemberInvite->value,
                RequestTypeEnum::organizationMemberApplication->value,
            ])
            ->where('status', RequestStatusEnum::pending->value)
            ->whereFor($dto->organization)
            ->where(function ($query) use ($dto) {
                $query->where(function ($query) use ($dto) {
                    $query->whereFrom($dto->organization)->whereTo($dto->member);
                })->orWhere(function ($query) use ($dto) {
                    $query->whereFrom($dto->member)->whereTo($dto->organization);
                });
            })
            ->exists();

        if ($exists) {
            throw new OrganizationException('There is already a pending request between this organization and user.', 422);
        }
    }
}
