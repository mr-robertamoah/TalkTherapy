<?php

namespace App\Services;

use App\Actions\GroupTherapy\CreateGroupTherapyAction;
use App\Actions\GroupTherapy\EnsureCanSetGroupTherapyPaymentGateAction;
use App\Actions\GroupTherapy\JoinGroupTherapyAction;
use App\Actions\GroupTherapy\UpdateGroupTherapyAction;
use App\Actions\Request\SendTherapyAssistanceRequestAction;
use App\Actions\Star\CreateStarAction;
use App\Actions\Therapy\DeleteTherapyAction;
use App\Actions\Therapy\EndTherapyAction;
use App\Actions\Therapy\EnsureCanCreateTherapyAction;
use App\Actions\Therapy\EnsureCanEndTherapyAction;
use App\Actions\Therapy\EnsureCanUpdateTherapyAction;
use App\Actions\Therapy\EnsureTherapyDataIsValidAction;
use App\Actions\Therapy\EnsureTherapyExistsAction;
use App\Actions\Therapy\EnsureUserHasAccessToTherapyAction;
use App\Actions\User\AlertGuardianAction;
use App\Actions\User\EnsureUserMeetsTherapyRequirementsAction;
use App\DTOs\CreateStarDTO;
use App\DTOs\GetTherapyDTO;
use App\DTOs\GroupTherapyDTO;
use App\DTOs\GuardianAlertDTO;
use App\DTOs\JoinGroupTherapyDTO;
use App\DTOs\TherapyAssistanceRequestDTO;
use App\Enums\PaginationEnum;
use App\Enums\StarTypeEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\User;
use App\Notifications\TherapyCreatedNotification;

class GroupTherapyService extends Service
{
    // max counsellors and users, allow anyone, shareequally and counsellors payment share
    public function createGroupTherapy(GroupTherapyDTO $groupTherapyDTO)
    {
        EnsureUserMeetsTherapyRequirementsAction::new()->execute($groupTherapyDTO->user);

        EnsureCanCreateTherapyAction::new()->execute($groupTherapyDTO->user);

        EnsureTherapyDataIsValidAction::new()->execute($groupTherapyDTO);

        $therapy = CreateGroupTherapyAction::new()->execute($groupTherapyDTO);

        if ($groupTherapyDTO->counsellor) {
            CreateStarAction::new()->execute(
                CreateStarDTO::fromArray([
                    'starredby' => null,
                    'starred' => $groupTherapyDTO->user,
                    'starreable' => $therapy,
                    'type' => StarTypeEnum::participation->value,
                ])
            );
        }

        AlertGuardianAction::new()->execute(
            GuardianAlertDTO::new()->fromArray([
                'user' => $groupTherapyDTO->user,
                'notification' => new TherapyCreatedNotification($therapy),
                // TT-4.10b/SCRUM-291: only pass the record when it actually corresponds to
                // $groupTherapyDTO->user -- CreateGroupTherapyAction sets addedby to
                // $groupTherapyDTO->counsellor when present, NOT to $groupTherapyDTO->user, so
                // the "created as a counsellor" branch must keep falling back to a live check on
                // the user's own account (unchanged from before this ticket) rather than reading
                // a snapshot that was correctly left null for a different reason entirely (no
                // single client at all, not "this user wasn't a minor").
                'for' => $groupTherapyDTO->counsellor ? null : $therapy,
            ])
        );

        SendTherapyAssistanceRequestAction::new()->execute(
            TherapyAssistanceRequestDTO::new()->fromArray([
                'from' => $groupTherapyDTO->counsellor ?: $groupTherapyDTO->user,
                'to' => $groupTherapyDTO->counsellorIds,
                'for' => $therapy,
            ])
        );

        return $therapy;
    }

    public function updateGroupTherapy(GroupTherapyDTO $groupTherapyDTO)
    {
        EnsureTherapyExistsAction::new()->execute(
            $groupTherapyDTO,
            'Group Therapy'
        );

        EnsureCanUpdateTherapyAction::new()->execute($groupTherapyDTO);

        // TT-7.5b-b1/SCRUM-265: defense-in-depth, mirrors TherapyService::updateTherapy()'s own
        // identical call -- only runs when one of the two gate-related fields is actually being
        // touched (EnsureCanSetGroupTherapyPaymentGateAction itself has no such guard, since it
        // doesn't own these field names; that's this call site's job). A paying client who passes
        // EnsureCanUpdateTherapyAction above (they ARE the addedby) still can't sneak a gate change
        // through the general update endpoint without also being an active counsellor or admin.
        if (! is_null($groupTherapyDTO->strictPaymentGate) || ! is_null($groupTherapyDTO->allowFreeHistoricalAccess)) {
            EnsureCanSetGroupTherapyPaymentGateAction::new()->execute($groupTherapyDTO->groupTherapy, $groupTherapyDTO->user);
        }

        EnsureTherapyDataIsValidAction::new()->execute($groupTherapyDTO);

        return UpdateGroupTherapyAction::new()->execute($groupTherapyDTO);
    }

    // TT-7.5b-b1/SCRUM-265: mirrors TherapyService::updateStrictPaymentGate()'s own precedent
    // exactly -- deliberately separate from updateGroupTherapy() above so an ACTIVE counsellor who
    // is NOT the group's own addedby (the normal case: a counsellor becomes assigned by accepting
    // an assistance request, not by being addedby) can still reach
    // EnsureCanSetGroupTherapyPaymentGateAction's own, self-contained authorization check, bypassing
    // EnsureCanUpdateTherapyAction's addedby-only gate. Also deliberately skips
    // EnsureTherapyDataIsValidAction, same rationale as TT-7.5a's own equivalent (these two
    // settings should stay toggleable regardless of the group's current status/other field state).
    public function updateGroupTherapyPaymentGate(GroupTherapyDTO $groupTherapyDTO)
    {
        EnsureTherapyExistsAction::new()->execute(
            $groupTherapyDTO,
            'Group Therapy'
        );

        EnsureCanSetGroupTherapyPaymentGateAction::new()->execute($groupTherapyDTO->groupTherapy, $groupTherapyDTO->user);

        return UpdateGroupTherapyAction::new()->execute($groupTherapyDTO);
    }

    public function endGroupTherapy(GroupTherapyDTO $groupTherapyDTO)
    {
        EnsureTherapyExistsAction::new()->execute(
            $groupTherapyDTO,
            'Group Therapy'
        );

        EnsureCanUpdateTherapyAction::new()->execute($groupTherapyDTO);

        EnsureCanEndTherapyAction::new()->execute($groupTherapyDTO);

        return EndTherapyAction::new()->execute($groupTherapyDTO);
    }

    public function deleteGroupTherapy(GroupTherapyDTO $groupTherapyDTO)
    {
        EnsureTherapyExistsAction::new()->execute(
            $groupTherapyDTO,
            'Group Therapy'
        );

        EnsureCanUpdateTherapyAction::new()->execute($groupTherapyDTO);

        return DeleteTherapyAction::new()->execute($groupTherapyDTO);
    }

    public function getGroupTherapy(GetTherapyDTO $getTherapyDTO)
    {
        EnsureTherapyExistsAction::new()->execute(
            $getTherapyDTO,
            'Group Therapy'
        );

        EnsureUserHasAccessToTherapyAction::new()->execute(
            $getTherapyDTO,
            'groupTherapy'
        );

        // TODO load relationships for efficiency
        return $getTherapyDTO->groupTherapy;
    }

    public function joinGroupTherapy(JoinGroupTherapyDTO $joinGroupTherapyDTO)
    {
        EnsureTherapyExistsAction::new()->execute(
            $joinGroupTherapyDTO,
            'Group Therapy'
        );

        return JoinGroupTherapyAction::new()->execute($joinGroupTherapyDTO);
    }

    public function getRandomGroupTherapies(?User $user)
    {
        // Eager-load users (with the per-member anonymity pivot) once for the whole page --
        // GroupTherapyMiniResource::isAnonymousFor() would otherwise lazy-load this pivot per
        // row (SCRUM-71 N+1 finding).
        $query = GroupTherapy::query()->with('users');

        $query->wherePublic();

        $query
            ->when($user, function ($query) use ($user) {
                $query->wherePublic();
                $query->where(function ($query) use ($user) {
                    $query->whereNot('addedby_id', $user->id)
                        ->where('addedby_type', User::class);
                });
            })
            ->when($user?->counsellor, function ($query) use ($user) {
                $query->wherePublic();
                $query->where(function ($query) use ($user) {
                    $query->where(function ($query) use ($user) {
                        $query->whereNot('addedby_id', $user->counsellor->id)
                            ->where('addedby_type', Counsellor::class);
                    });
                    $query->orWhere(function ($query) use ($user) {
                        $query->whereNotCounsellor($user->counsellor);
                    });
                });
            })
            ->inRandomOrder();

        return $query->paginate(PaginationEnum::preferencesPagination->value);
    }

    public function getCounsellorGroupTherapies(?User $user)
    {
        if (! $user->counsellor) {
            return [];
        }

        $query = GroupTherapy::query()->with('users');

        $query->where(function ($query) use ($user) {
            $query->where('addedby_id', $user->counsellor->id)
                ->where('addedby_type', Counsellor::class);
        });

        $query->orWhere(function ($query) use ($user) {
            $query->whereCounsellor($user->counsellor);
        });

        $query->orWhere(function ($query) use ($user) {
            $query->whereHas('discussions', function ($query) use ($user) {
                $query->whereHas('counsellors', function ($query) use ($user) {
                    $query->where('counsellor_id', $user->counsellor->id);
                });
            });
        });

        $query->orWhere(function ($query) use ($user) {
            $query->whereHas('discussions', function ($query) use ($user) {
                $query->whereHas('requests', function ($query) use ($user) {
                    $query
                        ->wherePending()
                        ->whereTo($user->counsellor);
                });
            });
        });

        $query->orWhereHas('requests', function ($query) use ($user) {
            $query
                ->wherePending()
                ->whereTo($user->counsellor);
        });

        $query->latest();

        return $query->paginate(PaginationEnum::preferencesPagination->value);
    }

    public function getUserGroupTherapies(?User $user)
    {
        if (! $user) {
            return [];
        }

        $query = GroupTherapy::query()->with('users');

        $query->where('addedby_id', $user->id);
        $query->where('addedby_type', User::class);

        $query->latest();

        return $query->paginate(PaginationEnum::preferencesPagination->value);
    }

    public function getWardGroupTherapies(?User $user)
    {
        if (! $user) {
            return [];
        }

        $query = GroupTherapy::query()->with('users');

        // TODO test getting them when ward is participating
        $query->where(function ($query) use ($user) {
            $query->whereHasMorph('addedby', [User::class], function ($query) use ($user) {
                $query->whereHas('guardians', function ($query) use ($user) {
                    $query->where('guardian_id', $user->id);
                });
            });
        })->orWhere(function ($query) use ($user) {
            $query->whereHas('users', function ($query) use ($user) {
                $query->whereHas('guardians', function ($query) use ($user) {
                    $query->where('guardian_id', $user->id);
                });
            });
        });

        $query->latest();

        return $query->paginate(PaginationEnum::preferencesPagination->value);
    }

    public function getRecentGroupTherapies(?User $user)
    {
        if (is_null($user)) {
            return [];
        }

        return GroupTherapy::query()
            ->whereParticipant($user)
            ->orderBy('created_at', 'desc')
            ->limit(3)
            ->get();
    }
}
