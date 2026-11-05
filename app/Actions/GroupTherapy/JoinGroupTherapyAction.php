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
use App\Models\User;
use App\Notifications\GroupTherapyMemberJoinedGuardianNotification;
use App\Notifications\GroupTherapyMembershipRequestSentNotification;
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

        // Ended, already taking part, or full -- the checks the add itself makes again under the lock.
        AddGroupTherapyMemberAction::new()->ensureCanAdd($groupTherapy, $user);

        if ($user->hasPendingRequestFor($groupTherapy)) {
            throw new CannotJoinGroupTherapyException('You already have a pending request to join this group therapy.', 422);
        }

        $anonymous = $groupTherapy->resolveMembershipAnonymity($joinGroupTherapyDTO->anonymous);

        if (! $groupTherapy->allow_anyone) {
            return $this->sendMembershipRequest($user, $groupTherapy, $anonymous);
        }

        return $this->attachImmediately($user, $groupTherapy, $anonymous);
    }

    // The checks above are just a fast-path rejection -- two concurrent immediate joins against a
    // near-full group could both pass them. AddGroupTherapyMemberAction re-checks under the group's
    // row lock (SCRUM-80). SCRUM-73: a former member rejoins through the same step, which also tells
    // a minor's guardians (a behaviour change, approved with the plan) once this commits.
    private function attachImmediately(User $user, GroupTherapy $groupTherapy, bool $anonymous)
    {
        return DB::transaction(function () use ($user, $groupTherapy, $anonymous) {
            $lockedGroupTherapy = GroupTherapy::query()->lockForUpdate()->findOrFail($groupTherapy->id);

            AddGroupTherapyMemberAction::new()->execute($lockedGroupTherapy, $user, $anonymous, GroupTherapyMemberJoinedGuardianNotification::VIA_BUTTON);

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
