<?php

namespace App\Actions\GroupTherapy;

use App\Actions\Action;
use App\Actions\CounsellorVerification\EnsureCounsellorMayTakeOnClientsAction;
use App\Actions\Guardianship\EnsureMinorHasActiveGuardianAction;
use App\Actions\Suspension\EnsureCanActAsCounsellorAction;
use App\Enums\RequestTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Exceptions\TherapyException;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Request;
use App\Models\User;
use Illuminate\Support\Collection;

// SCRUM-389: who may ask for a counsellor on a group after it was set up (POST
// /group-therapies/{id}/assist), run before anything that could reveal the group -- the mirror of
// EnsureCanSendTherapyAssistanceRequestAction:
// - its creator, inviting counsellors (currently verified, not suspended, not on it already, never
//   a member, nothing pending between them and the group);
// - a counsellor offering to help a PUBLIC group (the only kind a counsellor can find) that can take
//   another counsellor, they've never been part of, with nothing pending between them and it.
// Returns 'invite' or 'offer'.
class EnsureCanSendGroupTherapyAssistanceRequestAction extends Action
{
    public const NO_PLACES = 'This group already has its maximum number of counsellors.';

    public const ALREADY_PENDING = 'There is already a request waiting between you and this group. Check your requests.';

    public const INVITED = 'You have already been invited to this group. Accept it from your requests.';

    public const NO_COUNSELLORS = 'Choose at least one counsellor to invite.';

    public const COUNSELLOR_UNAVAILABLE = 'One of these counsellors can\'t be invited to this group.';

    public function execute(User $user, ?GroupTherapy $group, Collection $counsellors): string
    {
        // Nobody to answer: a group whose creator has gone (reviewer finding) is like a missing one.
        $creator = $group?->creatorUser();
        if (! $group || $group->status === TherapyStatusEnum::ended->value || ! $creator || $creator->trashed()
            || $group->creatorCounsellor()?->trashed()) {
            throw TherapyException::notAllowed();
        }

        if ($group->creatorUser()?->is($user)) {
            $this->ensureCanInvite($user, $group, $counsellors);

            return 'invite';
        }

        $counsellor = $user->counsellor;
        if (! $counsellor || ! $group->public || $group->isCounsellor($counsellor) || $group->hasEverBeenMember($user)) {
            throw TherapyException::notAllowed();
        }

        EnsureCanActAsCounsellorAction::new()->execute($counsellor);
        EnsureCounsellorMayTakeOnClientsAction::new()->execute($counsellor);
        // In a group, only the actor's own pause matters.
        EnsureMinorHasActiveGuardianAction::new()->execute($user, $group);

        if (! $group->canTakeCounsellor()) {
            throw new TherapyException(self::NO_PLACES, 422);
        }

        if ($this->pendingBetween($group, $counsellor)->whereTo($counsellor)->exists()) {
            throw new TherapyException(self::INVITED, 422);
        }

        if ($this->pendingBetween($group, $counsellor)->exists()) {
            throw new TherapyException(self::ALREADY_PENDING, 422);
        }

        return 'offer';
    }

    private function ensureCanInvite(User $user, GroupTherapy $group, Collection $counsellors): void
    {
        EnsureMinorHasActiveGuardianAction::new()->execute($user, $group);
        EnsureCanActAsCounsellorAction::new()->execute($group->creatorCounsellor());

        if ($counsellors->isEmpty()) {
            throw new TherapyException(self::NO_COUNSELLORS, 422);
        }

        if (! $group->canTakeCounsellor()) {
            throw new TherapyException(self::NO_PLACES, 422);
        }

        foreach ($counsellors as $counsellor) {
            EnsureCanActAsCounsellorAction::new()->execute($counsellor, asTarget: true);
            EnsureCounsellorMayTakeOnClientsAction::new()->execute($counsellor, asTarget: true);

            if ($group->isCounsellor($counsellor) || ! $counsellor->user || $group->hasEverBeenMember($counsellor->user)
                || $group->creatorUser()?->is($counsellor->user)) {
                throw new TherapyException(self::COUNSELLOR_UNAVAILABLE, 422);
            }

            if ($this->pendingBetween($group, $counsellor)->exists()) {
                throw new TherapyException(self::ALREADY_PENDING, 422);
            }
        }
    }

    // A pending group assistance request for $group between it and $counsellor, either direction.
    private function pendingBetween(GroupTherapy $group, Counsellor $counsellor)
    {
        return Request::query()
            ->wherePending()
            ->whereFor($group)
            ->where('type', RequestTypeEnum::groupTherapy->value)
            ->where(fn ($query) => $query->whereTo($counsellor)->orWhere(fn ($query) => $query->whereFrom($counsellor)));
    }
}
