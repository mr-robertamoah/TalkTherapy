<?php

namespace App\Actions\Transaction;

use App\Actions\Action;
use App\DTOs\TransactionDTO;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Enums\OrganizationMemberBillingModeEnum;
use App\Exceptions\TransactionException;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;

class EnsureOrganizationCanPayForModelAction extends Action
{
    // Deliberately ONE generic message for "not eligible via this org" -- mirrors
    // EnsureOrganizationCanReceiveMemberApplicationsAction's own anti-enumeration convention: a
    // wrong/nonexistent organizationId, a member not affiliated with it, an org that isn't
    // verified, or a counsellor it doesn't cover must all be indistinguishable, otherwise this
    // check becomes an oracle for probing arbitrary organizationId values against a real
    // member/counsellor pair.
    private const NOT_ELIGIBLE_MESSAGE = 'You are not authorized to pay for this via this organization.';

    // SCRUM-48 (TT-7.3a): the org-as-payer checks. A no-op for personal-pay (organizationId
    // absent) -- that path never reaches anything below the guard, so it's structurally, not just
    // behaviorally, unaffected by this action.
    public function execute(TransactionDTO $dto)
    {
        if (is_null($dto->organizationId)) {
            return;
        }

        // organizationId was supplied but didn't resolve to a real, verified, consumer-capable
        // Organization -- kept indistinguishable from every other "not eligible" reason below, not
        // a separate 404. is_consumer is re-checked here (not just at membership-creation time via
        // EnsureOrganizationIsConsumerAction) since an org can have that flag toggled off later
        // without its existing members being removed.
        if (is_null($dto->organization) || ! $dto->organization->isVerified() || ! $dto->organization->is_consumer) {
            throw new TransactionException(self::NOT_ELIGIBLE_MESSAGE, 403);
        }

        $member = $dto->user->organizationMemberships()
            ->where('organization_id', $dto->organization->id)
            ->first();

        if (is_null($member) || ! $member->isActive()) {
            throw new TransactionException(self::NOT_ELIGIBLE_MESSAGE, 403);
        }

        $for = $dto->for instanceof Session ? $dto->for->for : $dto->for;

        $counsellors = $for instanceof Therapy
            ? collect([$for->counsellor])->filter()
            : $for->activeCounsellors();

        $uncovered = $counsellors->isEmpty() || Counsellor::query()
            ->whereIn('id', $counsellors->pluck('id'))
            ->whereDoesntHave('organizationCounsellors', function ($query) use ($dto) {
                $query
                    ->where('organization_id', $dto->organization->id)
                    ->where('status', OrganizationCounsellorStatusEnum::active->value);
            })
            ->exists();

        if ($uncovered) {
            throw new TransactionException(self::NOT_ELIGIBLE_MESSAGE, 403);
        }

        // From here on, every rejection discloses only the requester's own membership/billing
        // status back to themselves -- safe to be specific, unlike the checks above.
        $billingConfig = $member->currentBillingConfig();

        if (is_null($billingConfig) || $billingConfig->mode !== OrganizationMemberBillingModeEnum::payPerUse->value) {
            throw new TransactionException(
                'Your organization covers this on a retainer basis -- no per-transaction payment is needed here.',
                422
            );
        }

        if ($for instanceof GroupTherapy && ! $billingConfig->include_group_therapies) {
            throw new TransactionException('Your organization billing does not cover group therapies.', 422);
        }
    }
}
