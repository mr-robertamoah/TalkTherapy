<?php

namespace App\Actions\GroupTherapy;

use App\Actions\Action;
use App\Actions\Video\RemovePersonFromLiveCallsAction;
use App\Enums\RequestStatusEnum;
use App\Enums\TherapyStatusEnum;
use App\Events\GroupTherapyLeftEvent;
use App\Events\GroupTherapyMembershipChangedEvent;
use App\Exceptions\CannotLeaveGroupTherapyException;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Request;
use App\Models\User;
use App\Notifications\GroupTherapyMemberLeftGuardianNotification;
use App\Notifications\GroupTherapyMemberLeftNotification;
use App\Notifications\SafeguardingExceptionRequestDecidedNotification;
use App\Support\BestEffort;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

// SCRUM-73: a member leaves a group. Their membership row is kept and stamped (left_at), so their
// messages stay in the history and report history still sees them; they lose the group's history
// unless it is public. Allowed while they are paused for a missing guardian or suspended -- leaving
// is a withdrawal. Nothing is refunded automatically; a refund can be asked for as usual.
//
// Lock order: the group, then the leaver's user row (new with this action). The group row
// serialises against every way of joining (AddGroupTherapyMemberAction) and the user row against
// a safeguarding-exception request for them (SendSafeguardingExceptionRequestAction, which locks the
// minor and re-reads their membership).
class LeaveGroupTherapyAction extends Action
{
    public const CREATOR = 'You created this group. End or delete it instead.';

    public const NOT_A_MEMBER = 'You are not a member of this group.';

    public const ALREADY_LEFT = 'You are no longer a member of this group.';

    public const ENDED = 'This group therapy has ended.';

    public const REQUEST_CLOSED_REASON = 'The member left the group.';

    public function execute(User $user, GroupTherapy $groupTherapy): GroupTherapy
    {
        [$group, $memberName, $wasMinor, $closedRequests] = DB::transaction(function () use ($user, $groupTherapy) {
            $group = GroupTherapy::query()->lockForUpdate()->findOrFail($groupTherapy->id);
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $this->ensureCanLeave($group, $user);

            // Before leaving: afterwards they have no row for these to read.
            $memberName = $group->displayNameOf($user);
            $wasMinor = $group->memberIsMinor($user);

            $group->allMemberships()->updateExistingPivot($user->id, ['left_at' => now()]);

            // A safeguarding exception asked for them in this group no longer has a member to cover.
            // No locking read (data.wardId has no index); the leaver's row lock above serialises
            // every writer of these requests, and each is saved by key.
            $closedRequests = Request::query()->pendingSafeguardingException($user, $group)->get()
                ->each(fn (Request $request) => $request->forceFill([
                    'status' => RequestStatusEnum::inconsequencial->value,
                    'responded_at' => now(),
                    'data' => [...($request->data ?? []), 'reason' => self::REQUEST_CLOSED_REASON],
                ])->save());

            return [$group, $memberName, $wasMinor, $closedRequests];
        });

        $this->afterLeaving($user, $group, $memberName, $wasMinor, $closedRequests);

        return $group;
    }

    private function ensureCanLeave(GroupTherapy $group, User $user): void
    {
        if ($group->isCreatedBy($user)) {
            throw new CannotLeaveGroupTherapyException(self::CREATOR, 422);
        }

        // A counsellor runs the group rather than belonging to it: the same answer as a stranger.
        $membership = $group->isCounsellorUser($user) ? null : $group->allMemberships()->whereKey($user->id)->first();

        if (! $membership) {
            throw new CannotLeaveGroupTherapyException(self::NOT_A_MEMBER, 422);
        }

        if ($membership->pivot->left_at) {
            throw new CannotLeaveGroupTherapyException(self::ALREADY_LEFT, 422);
        }

        if ($group->status === TherapyStatusEnum::ended->value) {
            throw new CannotLeaveGroupTherapyException(self::ENDED, 422);
        }
    }

    // Each step is best effort: the leave is saved, and one failing mustn't stop the others.
    private function afterLeaving(User $user, GroupTherapy $group, string $memberName, bool $wasMinor, Collection $closedRequests): void
    {
        $context = ['group_therapy_id' => $group->id, 'user_id' => $user->id];

        // Retries itself from the queue when it can't be done now.
        BestEffort::attempt(
            fn () => RemovePersonFromLiveCallsAction::new()->execute($user, RemovePersonFromLiveCallsAction::NO_LONGER_MEMBER),
            'group-leave-live-calls',
            $context,
        );

        BestEffort::attempt(
            fn () => Notification::send($this->teamOf($group, $user), new GroupTherapyMemberLeftNotification($group, $memberName, $user)),
            'group-leave-notify-team',
            $context,
        );

        if ($wasMinor) {
            BestEffort::attempt(
                fn () => Notification::send(User::query()->whereWard($user)->get(), new GroupTherapyMemberLeftGuardianNotification($group)),
                'group-leave-notify-guardians',
                $context,
            );
        }

        // The counsellors who asked for a safeguarding exception for them here: neutrally, that it
        // was closed (never why -- the leave itself reaches them as "a member left").
        $closedRequests->each(function (Request $request) use ($context) {
            $counsellor = $request->from;

            if ($counsellor instanceof Counsellor && $counsellor->user) {
                BestEffort::attempt(
                    fn () => $counsellor->user->notify(new SafeguardingExceptionRequestDecidedNotification($request, null, closed: true)),
                    'group-leave-notify-safeguarding-requester',
                    [...$context, 'request' => $request->id],
                );
            }
        });

        BestEffort::attempt(fn () => GroupTherapyLeftEvent::dispatch($user->id, $group->id), 'group-leave-broadcast-leaver', $context);
        BestEffort::attempt(fn () => GroupTherapyMembershipChangedEvent::dispatch($group->id), 'group-leave-broadcast-group', $context);

        Log::info('group_therapy.member_left', $context);
    }

    // The creator (as a person) and the group's active counsellors, never the leaver.
    private function teamOf(GroupTherapy $group, User $leaver)
    {
        $creator = $group->creatorUser();

        return $group->activeCounsellors()
            ->map(fn (Counsellor $counsellor) => $counsellor->user)
            ->push($creator)
            ->filter(fn ($person) => $person instanceof User && ! $person->is($leaver))
            ->unique('id')
            ->values();
    }
}
