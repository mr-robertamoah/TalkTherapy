<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Enums\OrganizationMemberBillingModeEnum;
use App\Enums\OrganizationMemberStatusEnum;
use App\Models\GroupTherapy;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Support\Collection;

// TT-7.3b-k/SCRUM-242: extracted from EnsureStrictPaymentGateSatisfiedAction's own
// isRetainerCoveredByAnOrg() (SCRUM-237) so the client-facing disclosure UI can ask the identical
// question -- "is this specific engagement covered by an org on a retainer basis" -- without a
// second, drifting copy of the same query. Returns the covering Organization (never a boolean)
// since the disclosure UI needs its name; EnsureStrictPaymentGateSatisfiedAction only needs
// whether the result is non-null.
//
// TT-7.5b-b2/SCRUM-266 (architect finding, mandatory): widened to GroupTherapy, which has no
// `counsellor` singular relation (only `counsellors()`/`activeCounsellors()`) -- the original
// `$therapy->counsellor` read would throw a hard TypeError the first time this ran for a
// strict-gated GroupTherapy via EnsureStrictPaymentGateSatisfiedAction. "Covered" for a
// GroupTherapy means covered via ANY of its currently-active counsellors, not just one -- a
// member is retainer-exempt if their org has an active retainer relationship with at least one
// counsellor actually running the group today.
//
// SCRUM-180: split into coverageFor() (one query per viewer: every organisation whose retainer covers them,
// and the counsellors it covers them with) and coveringOrganization() (which of those covers a given
// engagement), so a page of cards asks once per viewer, not once per card (ResolveTherapySponsorAction).
// execute() is the two together, so the payment gate and the badge can't drift apart.
class GetRetainerCoveringOrganizationAction extends Action
{
    public function execute(Therapy|GroupTherapy $therapy, User $user): ?Organization
    {
        $counsellorIds = $this->counsellorIdsOf($therapy);

        if ($counsellorIds->isEmpty()) {
            return null;
        }

        return $this->coverageFor($user, $counsellorIds)->first()['organization'] ?? null;
    }

    // In the order execute() always took them: [['organization' => Organization, 'counsellorIds' => Collection]].
    // $onlyCounsellorIds: just the organisations covering them with one of these counsellors (execute()'s
    // question, as narrow as it always was); null for every one (a page of cards).
    public function coverageFor(User $user, ?Collection $onlyCounsellorIds = null): Collection
    {
        $activeCounsellors = fn ($query) => $query
            ->where('status', OrganizationCounsellorStatusEnum::active->value)
            ->when($onlyCounsellorIds, fn ($query) => $query->whereIn('counsellor_id', $onlyCounsellorIds));

        return OrganizationMember::query()
            ->with([
                // Only their ids are read; kept off the organisation itself, so nothing later reads a
                // filtered relation as if it were all of them.
                'organization.organizationCounsellors' => fn ($query) => $activeCounsellors($query)->select(['id', 'organization_id', 'counsellor_id']),
                'latestBillingConfig',
            ])
            ->where('user_id', $user->id)
            ->where('status', OrganizationMemberStatusEnum::active->value)
            ->whereHas('organization', function ($query) {
                // Mirrors EnsureOrganizationCanPayForModelAction's own eligibility checks -- an
                // unverified or non-consumer org must not grant a free access bypass any more
                // than it could initiate a real charge.
                $query->where('is_consumer', true)->whereNotNull('verified_at');
            })
            ->whereHas('organization.organizationCounsellors', $activeCounsellors)
            ->get()
            ->filter(fn (OrganizationMember $member) => $member->currentBillingConfig()?->mode === OrganizationMemberBillingModeEnum::retainer->value)
            ->map(function (OrganizationMember $member) {
                $counsellorIds = $member->organization->organizationCounsellors->pluck('counsellor_id')->map(fn ($id) => (int) $id);
                $member->organization->unsetRelation('organizationCounsellors');

                return ['organization' => $member->organization, 'counsellorIds' => $counsellorIds];
            })
            ->values();
    }

    public function coveringOrganization(Collection $coverage, Collection $counsellorIds): ?Organization
    {
        $counsellorIds = $counsellorIds->map(fn ($id) => (int) $id);

        return $coverage->first(fn (array $entry) => $entry['counsellorIds']->intersect($counsellorIds)->isNotEmpty())['organization'] ?? null;
    }

    // A group's currently active counsellors (preloaded on a list -- activeCounsellorsForList()), or a
    // therapy's one.
    public function counsellorIdsOf(Therapy|GroupTherapy $therapy): Collection
    {
        return $therapy instanceof GroupTherapy
            ? $therapy->activeCounsellorsForList()->pluck('id')
            : collect([$therapy->counsellor?->id])->filter();
    }
}
