<?php

namespace App\Http\Resources\Concerns;

use App\Http\Resources\CounsellorMiniResource;
use App\Http\Resources\OrganizationMiniResource;
use App\Http\Resources\UserMiniResource;
use App\Models\Counsellor;
use App\Models\Organization;
use App\Models\User;

// Shared by OrganizationRequestResource and OrganizationCounsellorCompensationNegotiationStateResource
// (both render a Request's from/to, which for the org-context request types can be an
// Organization, a Counsellor, or a User) -- extracted once a second resource needed the exact
// same type-switch (review finding, PR #89).
trait ResolvesOrganizationOrCounsellorParty
{
    private function partyResource(?string $type, $model)
    {
        if ($type === Organization::class) {
            return new OrganizationMiniResource($model);
        }

        if ($type === Counsellor::class) {
            return new CounsellorMiniResource($model);
        }

        if ($type === User::class) {
            return new UserMiniResource($model);
        }

        return null;
    }
}
