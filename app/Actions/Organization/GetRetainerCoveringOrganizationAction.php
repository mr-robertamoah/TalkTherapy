<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Enums\OrganizationMemberBillingModeEnum;
use App\Enums\OrganizationMemberStatusEnum;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Therapy;
use App\Models\User;

// TT-7.3b-k/SCRUM-242: extracted from EnsureStrictPaymentGateSatisfiedAction's own
// isRetainerCoveredByAnOrg() (SCRUM-237) so the client-facing disclosure UI can ask the identical
// question -- "is this specific engagement covered by an org on a retainer basis" -- without a
// second, drifting copy of the same query. Returns the covering Organization (never a boolean)
// since the disclosure UI needs its name; EnsureStrictPaymentGateSatisfiedAction only needs
// whether the result is non-null.
class GetRetainerCoveringOrganizationAction extends Action
{
    public function execute(Therapy $therapy, User $user): ?Organization
    {
        if (! $therapy->counsellor) {
            return null;
        }

        $member = OrganizationMember::query()
            ->with(['organization', 'latestBillingConfig'])
            ->where('user_id', $user->id)
            ->where('status', OrganizationMemberStatusEnum::active->value)
            ->whereHas('organization', function ($query) {
                // Mirrors EnsureOrganizationCanPayForModelAction's own eligibility checks -- an
                // unverified or non-consumer org must not grant a free access bypass any more
                // than it could initiate a real charge.
                $query->where('is_consumer', true)->whereNotNull('verified_at');
            })
            ->whereHas('organization.organizationCounsellors', function ($query) use ($therapy) {
                $query
                    ->where('counsellor_id', $therapy->counsellor->id)
                    ->where('status', OrganizationCounsellorStatusEnum::active->value);
            })
            ->get()
            ->first(fn (OrganizationMember $member) => $member->currentBillingConfig()?->mode === OrganizationMemberBillingModeEnum::retainer->value);

        return $member?->organization;
    }
}
