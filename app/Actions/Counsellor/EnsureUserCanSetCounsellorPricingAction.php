<?php

namespace App\Actions\Counsellor;

use App\Actions\Action;
use App\DTOs\CounsellorPricingDTO;
use App\Exceptions\CounsellorException;

class EnsureUserCanSetCounsellorPricingAction extends Action
{
    // Self-service only, plus a platform-admin bypass -- mirrors the codebase's established
    // Action-based (not Policy-based) authorization convention. There is no org-admin path here,
    // unlike EnsureUserCanSetOrganizationMemberBillingConfigAction: pricing is the counsellor's
    // own, unilateral, informational number, not something an organization sets on their behalf.
    public function execute(CounsellorPricingDTO $dto): void
    {
        if (is_null($dto->user)) {
            throw new CounsellorException('You are not authorized to set this pricing.', 403);
        }

        if ($dto->user->isAdmin()) {
            return;
        }

        if ($dto->user->counsellor?->id !== $dto->counsellor->id) {
            throw new CounsellorException('You are not authorized to set this pricing.', 403);
        }
    }
}
