<?php

namespace App\Services;

use App\Actions\Request\SendTherapyAssistanceRequestAction;
use App\Actions\Star\CreateStarAction;
use App\Actions\GroupTherapy\CreateGroupTherapyAction;
use App\Actions\GroupTherapy\UpdateGroupTherapyAction;
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

        if ($groupTherapyDTO->counsellor)
            CreateStarAction::new()->execute(
                CreateStarDTO::fromArray([
                    'starredby' => null,
                    'starred' => $groupTherapyDTO->user,
                    'starreable' => $therapy,
                    'type' => StarTypeEnum::participation->value,
                ])
            );

        AlertGuardianAction::new()->execute(
            GuardianAlertDTO::new()->fromArray([
                'user' => $groupTherapyDTO->user,
                'notification' => new TherapyCreatedNotification($therapy)
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

        EnsureTherapyDataIsValidAction::new()->execute($groupTherapyDTO);

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

        EnsureUserHasAccessToTherapyAction::new()->execute($getTherapyDTO);

        // TODO load relationships for efficiency
        return $getTherapyDTO->therapy;
    }

    public function getRandomGroupTherapies(?User $user)
    {
        $query = GroupTherapy::query();

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
        if (!$user->counsellor) return [];

        $query = GroupTherapy::query();
        
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
        if (!$user) return [];

        $query = GroupTherapy::query();
        
        $query->where('addedby_id', $user->id);
        $query->where('addedby_type', User::class);

        $query->latest();

        return $query->paginate(PaginationEnum::preferencesPagination->value);
    }

    public function getWardGroupTherapies(?User $user)
    {
        if (!$user) return [];

        $query = GroupTherapy::query();
        
        // TODO test getting them when ward is participating
        $query->where(function ($query) use ($user) {
            $query->whereHasMorph('addedby', [User::class], function ($query) use ($user) {
                $query->whereHas('guardians', function($query) use ($user) {
                    $query->where('guardian_id', $user->id);
                });
            });
        })->orWhere(function ($query) use ($user) {
            $query->whereHas('users', function ($query) use ($user) {
                $query->whereHas('guardians', function($query) use ($user) {
                    $query->where('guardian_id', $user->id);
                });
            });
        });

        $query->latest();

        return $query->paginate(PaginationEnum::preferencesPagination->value);
    }
}