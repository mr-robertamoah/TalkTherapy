<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Enums\OrganizationCounsellorSourceEnum;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\Counsellor;
use App\Models\OrganizationCounsellor;
use App\Models\Request;
use Illuminate\Database\UniqueConstraintViolationException;

class CreateOrganizationCounsellorAffiliationAction extends Action
{
    // Called from RespondToOrganizationCounsellorRequestAction once a request has already
    // transitioned to accepted -- the resulting row starts `pending`; TT-6.4b (SCRUM-122)
    // transitions it to `active` once compensation terms are agreed.
    //
    // If a row for this (organization, counsellor) pair already exists, this intentionally
    // leaves it untouched rather than resetting its status/source -- e.g. a previously `ended`
    // affiliation being re-accepted does NOT reactivate it here. Making re-affiliation an
    // explicit, deliberate action (rather than an accept-time side effect silently reviving a
    // stale row) is left to whichever ticket introduces the `ended` status transition.
    public function execute(Request $request): OrganizationCounsellor
    {
        $organization = $request->for;
        $counsellor = $request->from instanceof Counsellor ? $request->from : $request->to;

        EnsureCounsellorIsPlatformVerifiedAction::new()->execute($counsellor);

        $existing = OrganizationCounsellor::query()
            ->where('organization_id', $organization->id)
            ->where('counsellor_id', $counsellor->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $source = $request->type === RequestTypeEnum::organizationCounsellorApplication->value
            ? OrganizationCounsellorSourceEnum::applied->value
            : OrganizationCounsellorSourceEnum::invited->value;

        // Mirrors RespondToGuardianshipRequestAction::createGuardianshipIfMissing() -- the
        // existence check above isn't atomic with this insert, so two concurrently-accepted
        // requests for the same pair (e.g. an invite and an application both accepted at once)
        // can both pass the check; the loser degrades to re-fetching the winner's row instead
        // of surfacing an uncaught constraint-violation error.
        try {
            return OrganizationCounsellor::query()->create([
                'organization_id' => $organization->id,
                'counsellor_id' => $counsellor->id,
                'status' => OrganizationCounsellorStatusEnum::pending->value,
                'source' => $source,
            ]);
        } catch (UniqueConstraintViolationException) {
            return OrganizationCounsellor::query()
                ->where('organization_id', $organization->id)
                ->where('counsellor_id', $counsellor->id)
                ->first();
        }
    }
}
