<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Models\Counsellor;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;

// TT-7.3b-b0/SCRUM-232: the single reusable "does this org actively cover this counsellor, and
// what's the affiliation" lookup -- extracted so TT-7.3b-b/-d/-j don't each reimplement the same
// query EnsureOrganizationCanPayForModelAction already does inline for its own (multi-counsellor,
// GroupTherapy-aware) coverage check. Deliberately not a replacement for that action's own
// batch/set-based check (a GroupTherapy's "every active counsellor must be covered" rule is a
// different shape of query than this single-counsellor lookup) -- this is for callers that
// already have one specific counsellor in hand and need that counsellor's own affiliation row.
class GetActiveOrganizationCounsellorAction extends Action
{
    public function execute(Counsellor $counsellor, Organization $organization): ?OrganizationCounsellor
    {
        return OrganizationCounsellor::query()
            ->where('organization_id', $organization->id)
            ->where('counsellor_id', $counsellor->id)
            ->where('status', OrganizationCounsellorStatusEnum::active->value)
            ->with('latestCompensation')
            ->first();
    }
}
