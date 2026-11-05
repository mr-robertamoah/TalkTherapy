<?php

namespace App\Actions\GroupTherapy;

use App\Actions\Action;
use App\Enums\CounsellorGroupTherapyRoleEnum;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Exceptions\CannotJoinGroupTherapyException;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Request;
use App\Models\User;

// SCRUM-358/383: the one way a counsellor joins a group's team -- accepting an assistance invite
// (RespondToGroupTherapyAssistanceRequestAction) and using a counsellor invitation link
// (PerformGroupTherapyLinkAction). The caller holds the group's row lock (inside its transaction).
// The counsellor becomes an active, normal-role counsellor (a role they already had is kept), unless
// the group is already full. Once it fills, the other pending invites close.
class AddCounsellorToGroupTherapyAction extends Action
{
    public const GROUP_FULL = 'This group already has its maximum number of counsellors.';

    public const NOT_ALLOWED = 'This invitation can no longer be accepted.';

    // $exceptRequestId: the invite being accepted, left for its caller to close.
    public function execute(GroupTherapy $lockedGroup, ?Counsellor $counsellor, ?User $actor, ?int $exceptRequestId = null): Counsellor
    {
        $this->ensureCanJoin($lockedGroup, $counsellor, $actor);

        $activeCounsellors = $lockedGroup->activeCounsellors();
        $alreadyActive = $activeCounsellors->contains('id', $counsellor->id);

        if (! $alreadyActive && $activeCounsellors->count() >= $lockedGroup->max_counsellors) {
            throw new CannotJoinGroupTherapyException(self::GROUP_FULL, 422);
        }

        $existing = $lockedGroup->counsellors()->whereKey($counsellor->id)->first();
        $lockedGroup->counsellors()->syncWithoutDetaching([
            $counsellor->id => [
                'state' => CounsellorGroupTherapyStateEnum::active->value,
                'role' => $existing?->pivot->role ?? CounsellorGroupTherapyRoleEnum::normal->value,
            ],
        ]);

        if ($lockedGroup->activeCounsellors()->count() >= $lockedGroup->max_counsellors) {
            Request::query()
                ->when($exceptRequestId, fn ($query) => $query->whereNot('id', $exceptRequestId))
                ->wherePending()
                ->whereFor($lockedGroup)
                ->where('type', RequestTypeEnum::groupTherapy->value)
                ->update([
                    'status' => RequestStatusEnum::inconsequencial->value,
                    'responded_at' => now(),
                    'data' => ['reason' => 'This group has filled all its counsellor places.'],
                ]);
        }

        return $counsellor;
    }

    // Security review (SCRUM-358): it must be someone who may join: the counsellor themselves (an
    // admin can't accept on their behalf -- joining is their consent), still verified, not the
    // group's own client or a member (who would gain moderation powers over the other members), and a
    // group that hasn't ended. One answer for all of these. SCRUM-73: a former member counts as a
    // member here (the cautious choice) -- they knew the other members as one of them.
    public function ensureCanJoin(GroupTherapy $group, ?Counsellor $counsellor, ?User $actor): void
    {
        $allowed = $counsellor
            && $counsellor->user
            && $actor && $counsellor->user->is($actor)
            && $counsellor->verified_at
            && $group->status !== TherapyStatusEnum::ended->value
            && ! $group->isUser($counsellor->user)
            && ! $group->hasEverBeenMember($counsellor->user);

        if (! $allowed) {
            throw new CannotJoinGroupTherapyException(self::NOT_ALLOWED, 422);
        }
    }
}
