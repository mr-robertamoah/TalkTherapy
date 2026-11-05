<?php

namespace App\Actions\GroupTherapy;

use App\Actions\Action;
use App\Actions\User\AlertGuardianAction;
use App\DTOs\GuardianAlertDTO;
use App\Enums\TherapyStatusEnum;
use App\Events\GroupTherapyMembershipChangedEvent;
use App\Exceptions\CannotJoinGroupTherapyException;
use App\Models\GroupTherapy;
use App\Models\User;
use App\Notifications\GroupTherapyMemberJoinedGuardianNotification;
use App\Support\BestEffort;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

// SCRUM-73: the one way someone becomes a member of a group -- the join button
// (JoinGroupTherapyAction), an accepted membership request
// (RespondToGroupTherapyMembershipRequestAction) and a member invitation link
// (PerformGroupTherapyLinkAction). The caller holds the group's row lock (inside its transaction),
// so the capacity check and the write can't be raced.
//  - Refused: an ended group, a full one (current members only), and anyone already taking part
//    (a current member, the creator, a counsellor).
//  - Rejoining reuses the old row: left_at is cleared, anonymity is chosen afresh (the group's own
//    flag still wins), was_minor_at_join sticks once true, and created_at is kept -- the original
//    join date is the one the free-history rule reads (the user's choice).
//  - Once the caller's transaction commits (best effort, each step on its own): a minor's guardians
//    are told ($joinedVia picks the wording), and the group's open pages reload it
//    (GroupTherapyMembershipChangedEvent, as when someone leaves).
// Returns whether a new row was created (false: a rejoin).
class AddGroupTherapyMemberAction extends Action
{
    public const ENDED = 'This group therapy has ended.';

    public const FULL = 'This group therapy has reached its maximum number of members.';

    public const ALREADY_MEMBER = 'You are already a participant of this group therapy.';

    // $joinedVia: one of GroupTherapyMemberJoinedGuardianNotification::VIA_*.
    public function execute(GroupTherapy $lockedGroup, User $user, bool $anonymous, string $joinedVia): bool
    {
        $this->ensureCanAdd($lockedGroup, $user);

        $anonymous = $lockedGroup->resolveMembershipAnonymity($anonymous);
        $former = $lockedGroup->allMemberships()->whereKey($user->id)->first();

        if ($former) {
            $lockedGroup->allMemberships()->updateExistingPivot($user->id, [
                'left_at' => null,
                'anonymous' => $anonymous,
                'was_minor_at_join' => (bool) $former->pivot->was_minor_at_join || ! $user->isAdult(),
            ]);
        } else {
            try {
                // TT-3.2f-b/SCRUM-319: was_minor_at_join is the per-membership snapshot
                // GroupTherapy::memberIsMinor() reads.
                $lockedGroup->allMemberships()->attach($user->id, [
                    'anonymous' => $anonymous,
                    'was_minor_at_join' => ! $user->isAdult(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // A double-submitted click from the same person (SCRUM-80).
                throw new CannotJoinGroupTherapyException(self::ALREADY_MEMBER, 422);
            }
        }

        $this->afterJoining($lockedGroup, $user, $joinedVia);

        return ! $former;
    }

    // The same checks without writing, for a caller that wants to refuse early (outside the lock),
    // or to turn a refusal into its own answer (an accepted request that can no longer be met).
    public function ensureCanAdd(GroupTherapy $group, User $user): void
    {
        if ($group->status === TherapyStatusEnum::ended->value) {
            throw new CannotJoinGroupTherapyException(self::ENDED, 422);
        }

        if ($group->isParticipant($user)) {
            throw new CannotJoinGroupTherapyException(self::ALREADY_MEMBER, 422);
        }

        if ($group->users()->count() >= $group->max_users) {
            throw new CannotJoinGroupTherapyException(self::FULL, 422);
        }
    }

    // Run straight away when there is no open transaction.
    private function afterJoining(GroupTherapy $group, User $user, string $joinedVia): void
    {
        $context = ['group_therapy_id' => $group->id, 'user_id' => $user->id];

        DB::afterCommit(function () use ($group, $user, $joinedVia, $context) {
            // TT-4.10b/SCRUM-291: no 'for' => $group -- $user is the joining member, not the group's
            // creator, so the group's client_was_minor_at_creation would answer for someone else. The
            // live isAdult() check is the right answer at the moment they join.
            BestEffort::attempt(
                fn () => AlertGuardianAction::new()->execute(GuardianAlertDTO::new()->fromArray([
                    'user' => $user,
                    'notification' => new GroupTherapyMemberJoinedGuardianNotification($group, $joinedVia),
                ])),
                'group-join-notify-guardians',
                $context,
            );

            BestEffort::attempt(fn () => GroupTherapyMembershipChangedEvent::dispatch($group->id), 'group-join-broadcast-group', $context);
        });
    }
}
