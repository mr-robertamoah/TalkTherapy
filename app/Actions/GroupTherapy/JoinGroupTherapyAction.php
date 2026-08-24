<?php

namespace App\Actions\GroupTherapy;

use App\Actions\Action;
use App\Actions\Request\CreateRequestAction;
use App\Actions\Therapy\EnsureCanCreateTherapyAction;
use App\DTOs\CreateRequestDTO;
use App\DTOs\JoinGroupTherapyDTO;
use App\Enums\RequestTypeEnum;
use App\Exceptions\CannotJoinGroupTherapyException;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Notifications\GroupTherapyMembershipRequestSentNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class JoinGroupTherapyAction extends Action
{
    public function execute(JoinGroupTherapyDTO $joinGroupTherapyDTO)
    {
        $user = $joinGroupTherapyDTO->user;
        $groupTherapy = $joinGroupTherapyDTO->groupTherapy;

        // Guardian/minor gate -- reused directly per architect review, despite living in the
        // Therapy namespace: it's a generic user-eligibility check, not Therapy-specific.
        EnsureCanCreateTherapyAction::new()->execute($user);

        if ($groupTherapy->isParticipant($user)) {
            throw new CannotJoinGroupTherapyException('You are already a participant of this group therapy.', 422);
        }

        if ($user->hasPendingRequestFor($groupTherapy)) {
            throw new CannotJoinGroupTherapyException('You already have a pending request to join this group therapy.', 422);
        }

        if ($groupTherapy->users()->count() >= $groupTherapy->max_users) {
            throw new CannotJoinGroupTherapyException('This group therapy has reached its maximum number of members.', 422);
        }

        $anonymous = $groupTherapy->resolveMembershipAnonymity($joinGroupTherapyDTO->anonymous);

        if (! $groupTherapy->allow_anyone) {
            return $this->sendMembershipRequest($user, $groupTherapy, $anonymous);
        }

        return $this->attachImmediately($user, $groupTherapy, $anonymous);
    }

    // The check above is just a fast-path rejection -- two concurrent immediate joins against
    // a near-full group could both pass it before either attaches. Re-check capacity under a
    // row lock immediately before attaching, and fall back to the friendly "already a
    // participant" message if a race still slips through the DB's unique constraint on
    // (group_therapy_id, user_id) -- e.g. a double-submitted click from the same user (SCRUM-80).
    private function attachImmediately($user, GroupTherapy $groupTherapy, bool $anonymous)
    {
        return DB::transaction(function () use ($user, $groupTherapy, $anonymous) {
            $lockedGroupTherapy = GroupTherapy::query()->lockForUpdate()->findOrFail($groupTherapy->id);

            if ($lockedGroupTherapy->users()->count() >= $lockedGroupTherapy->max_users) {
                throw new CannotJoinGroupTherapyException('This group therapy has reached its maximum number of members.', 422);
            }

            try {
                $lockedGroupTherapy->users()->attach($user->id, ['anonymous' => $anonymous]);
            } catch (UniqueConstraintViolationException) {
                throw new CannotJoinGroupTherapyException('You are already a participant of this group therapy.', 422);
            }

            return $lockedGroupTherapy->refresh();
        });
    }

    private function sendMembershipRequest($user, $groupTherapy, bool $anonymous)
    {
        // The group's creator (addedby) may be a User or a Counsellor -- the request always
        // goes `to` a User, mirroring GroupTherapy::getUsers()'s own addedby-to-User resolution.
        $creator = $groupTherapy->addedby_type === Counsellor::class
            ? $groupTherapy->addedby?->user
            : $groupTherapy->addedby;

        $request = CreateRequestAction::new()->execute(
            CreateRequestDTO::new()->fromArray([
                'from' => $user,
                'to' => $creator,
                'for' => $groupTherapy,
                'type' => RequestTypeEnum::groupTherapyMembership->value,
                'data' => ['anonymous' => $anonymous],
            ])
        );

        $creator?->notify(
            new GroupTherapyMembershipRequestSentNotification($request)
        );

        return $request;
    }
}
