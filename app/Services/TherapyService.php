<?php

namespace App\Services;

use App\Actions\Request\SendTherapyAssistanceRequestAction;
use App\Actions\Star\CreateStarAction;
use App\Actions\Therapy\CreateTherapyAction;
use App\Actions\Therapy\DeleteTherapyAction;
use App\Actions\Therapy\EndTherapyAction;
use App\Actions\Therapy\EnsureCanCreateTherapyAction;
use App\Actions\Therapy\EnsureCanEndTherapyAction;
use App\Actions\Therapy\EnsureCanUpdateTherapyAction;
use App\Actions\Therapy\EnsureIsCounsellorAction;
use App\Actions\Therapy\EnsureIsNotACounsellorAction;
use App\Actions\Therapy\EnsureTherapyDataIsValidAction;
use App\Actions\Therapy\EnsureTherapyExistsAction;
use App\Actions\Therapy\EnsureTherapyHasNoAssistanceAction;
use App\Actions\Therapy\EnsureThereIsNoPendingRequestForCounsellorAction;
use App\Actions\Therapy\EnsureThereIsNoPendingRequestForCounsellorsAction;
use App\Actions\Therapy\EnsureUserHasAccessToTherapyAction;
use App\Actions\Therapy\UpdateTherapyAction;
use App\Actions\User\AlertGuardianAction;
use App\Actions\User\EnsureUserMeetsTherapyRequirementsAction;
use App\DTOs\AssistTherapyDTO;
use App\DTOs\CreateStarDTO;
use App\DTOs\CreateTherapyDTO;
use App\DTOs\GetTherapyDTO;
use App\DTOs\GuardianAlertDTO;
use App\DTOs\TherapyAssistanceRequestDTO;
use App\Enums\PaginationEnum;
use App\Enums\StarTypeEnum;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\TherapyCreatedNotification;

class TherapyService extends Service
{
    public function createTherapy(CreateTherapyDTO $createTherapyDTO)
    {
        EnsureUserMeetsTherapyRequirementsAction::new()->execute($createTherapyDTO->user);

        EnsureCanCreateTherapyAction::new()->execute($createTherapyDTO);

        EnsureTherapyDataIsValidAction::new()->execute($createTherapyDTO);

        $therapy = CreateTherapyAction::new()->execute($createTherapyDTO);

        CreateStarAction::new()->execute(
            CreateStarDTO::fromArray([
                'starredby' => null,
                'starred' => $createTherapyDTO->user,
                'starreable' => $therapy,
                'type' => StarTypeEnum::participation->value,
            ])
        );

        AlertGuardianAction::new()->execute(
            GuardianAlertDTO::new()->fromArray([
                'user' => $createTherapyDTO->user,
                'notification' => new TherapyCreatedNotification($therapy)
            ])
        );

        SendTherapyAssistanceRequestAction::new()->execute(
            TherapyAssistanceRequestDTO::new()->fromArray([
                'from' => $createTherapyDTO->user,
                'to' => $createTherapyDTO->counsellor,
                'for' => $therapy,
            ])
        );

        return $therapy;
    }

    public function updateTherapy(CreateTherapyDTO $createTherapyDTO)
    {
        EnsureTherapyExistsAction::new()->execute($createTherapyDTO);

        EnsureCanUpdateTherapyAction::new()->execute($createTherapyDTO);

        EnsureTherapyDataIsValidAction::new()->execute($createTherapyDTO);

        return UpdateTherapyAction::new()->execute($createTherapyDTO);
    }

    public function endTherapy(CreateTherapyDTO $createTherapyDTO)
    {
        EnsureTherapyExistsAction::new()->execute($createTherapyDTO);

        EnsureCanEndTherapyAction::new()->execute($createTherapyDTO);

        return EndTherapyAction::new()->execute($createTherapyDTO);
    }

    public function deleteTherapy(CreateTherapyDTO $createTherapyDTO)
    {
        EnsureTherapyExistsAction::new()->execute($createTherapyDTO);

        EnsureCanUpdateTherapyAction::new()->execute($createTherapyDTO);

        return DeleteTherapyAction::new()->execute($createTherapyDTO);
    }

    public function getTherapy(GetTherapyDTO $getTherapyDTO)
    {
        EnsureTherapyExistsAction::new()->execute($getTherapyDTO);

        EnsureUserHasAccessToTherapyAction::new()->execute($getTherapyDTO);

        // TODO load relationships for efficiency
        return $getTherapyDTO->therapy;
    }

    public function getTherapies(User $user)
    {
        $query = Therapy::query();

        $query->whereParticipant($user);
        
        $query->orderByDesc('created_at');

        return $query->paginate(PaginationEnum::homePagination->value);
    }

    public function getRecentTherapies(User|null $user)
    {
        if (is_null($user)) return [];

        return Therapy::query()
            ->whereParticipant($user)
            ->orderBy('created_at', 'desc')
            ->limit(3)
            ->get();
    }

    public function sendAssistanceRequest(AssistTherapyDTO $assistTherapyDTO)
    {
        EnsureTherapyExistsAction::new()->execute($assistTherapyDTO);

        EnsureTherapyHasNoAssistanceAction::new()->execute($assistTherapyDTO);

        if ($assistTherapyDTO->therapy->isUser($assistTherapyDTO->user)) {
            
            EnsureIsNotACounsellorAction::new()->execute($assistTherapyDTO);

            EnsureThereIsNoPendingRequestForCounsellorsAction::new()->execute($assistTherapyDTO);

            foreach ($assistTherapyDTO->counsellors as $counsellor)
                SendTherapyAssistanceRequestAction::new()->execute(
                    TherapyAssistanceRequestDTO::new()->fromArray([
                        'from' => $assistTherapyDTO->user,
                        'to' => $counsellor,
                        'for' => $assistTherapyDTO->therapy,
                    ])
                );

            return;
        }

        EnsureIsCounsellorAction::new()->execute($assistTherapyDTO);

        EnsureThereIsNoPendingRequestForCounsellorAction::new()->execute($assistTherapyDTO);
        
        SendTherapyAssistanceRequestAction::new()->execute(
            TherapyAssistanceRequestDTO::new()->fromArray([
                'from' => $assistTherapyDTO->user->counsellor,
                'to' => $assistTherapyDTO->therapy->addedby,
                'for' => $assistTherapyDTO->therapy,
            ])
        );
    }

    public function getRandomTherapies(?User $user)
    {
        $query = Therapy::query();

        $query->wherePublic();
        
        $query
            ->when($user, function ($query) use ($user) {
                $query->wherePublic();
                $query->orWhereNot('addedby_id', $user->id);
            })
            ->when($user?->counsellor, function ($query) use ($user) {
                $query->wherePublic();
                $query->where(function ($query) use ($user) {
                    $query->whereNoCounsellor($user->counsellor);
                    $query->orWhereNot('counsellor_id', $user->counsellor->id);
                });
            })
            ->inRandomOrder();

        return $query->paginate(PaginationEnum::preferencesPagination->value);
    }

    public function getCounsellorTherapies(?User $user)
    {
        if (!$user->counsellor) return [];

        $query = Therapy::query();
        
        $query->where('counsellor_id', $user->counsellor->id);

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

    public function getUserTherapies(?User $user)
    {
        if (!$user) return [];

        $query = Therapy::query();
        
        $query->where('addedby_id', $user->id);
        $query->where('addedby_type', User::class);

        $query->latest();

        return $query->paginate(PaginationEnum::preferencesPagination->value);
    }

    public function getWardTherapies(?User $user)
    {
        if (!$user) return [];

        $query = Therapy::query();
        
        $query->whereHasMorph('addedby', [User::class], function ($query) use ($user) {
            $query->whereHas('guardians', function($query) use ($user) {
                $query->where('guardian_id', $user->id);
            });
        });

        $query->latest();

        return $query->paginate(PaginationEnum::preferencesPagination->value);
    }
}