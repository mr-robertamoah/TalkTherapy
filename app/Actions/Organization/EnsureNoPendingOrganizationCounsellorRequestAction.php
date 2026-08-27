<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationCounsellorRequestDTO;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Exceptions\OrganizationException;
use App\Models\Request;

class EnsureNoPendingOrganizationCounsellorRequestAction extends Action
{
    // Checked in both directions -- an org can't invite a counsellor who already has a
    // pending application to it, and vice versa.
    public function execute(OrganizationCounsellorRequestDTO $dto): void
    {
        $exists = Request::query()
            ->whereIn('type', [
                RequestTypeEnum::organizationCounsellorInvite->value,
                RequestTypeEnum::organizationCounsellorApplication->value,
            ])
            ->where('status', RequestStatusEnum::pending->value)
            ->whereFor($dto->organization)
            ->where(function ($query) use ($dto) {
                $query->where(function ($query) use ($dto) {
                    $query->whereFrom($dto->organization)->whereTo($dto->counsellor);
                })->orWhere(function ($query) use ($dto) {
                    $query->whereFrom($dto->counsellor)->whereTo($dto->organization);
                });
            })
            ->exists();

        if ($exists) {
            throw new OrganizationException('There is already a pending request between this organization and counsellor.', 422);
        }
    }
}
