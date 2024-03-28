<?php

namespace App\Services;

use App\Actions\Request\SendTherapyAssistanceRequestAction;
use App\Actions\Star\CreateStarAction;
use App\Actions\Therapy\CreateTherapyAction;
use App\Actions\Therapy\EnsureCanCreateTherapyAction;
use App\Actions\Therapy\EnsureTherapyDataIsValidAction;
use App\Actions\Therapy\EnsureTherapyExistsAction;
use App\Actions\Therapy\EnsureUserHasAccessToTherapyAction;
use App\DTOs\CreateStarDTO;
use App\DTOs\CreateTherapyDTO;
use App\DTOs\GetTherapyDTO;
use App\DTOs\TherapyAssistanceRequestDTO;
use App\Enums\PaginationEnum;
use App\Enums\StarTypeEnum;
use App\Models\Therapy;
use App\Models\User;

class TherapyService extends Service
{
    public function createTherapy(CreateTherapyDTO $createTherapyDTO)
    {
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

        SendTherapyAssistanceRequestAction::new()->execute(
            TherapyAssistanceRequestDTO::new()->fromArray([
                'from' => $createTherapyDTO->user,
                'to' => $createTherapyDTO->counsellor,
                'for' => $therapy,
            ])
        );

        return $therapy;
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
            ->where('addedby_type', $user::class)
            ->where('addedby_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit(3)
            ->get();
    }
}