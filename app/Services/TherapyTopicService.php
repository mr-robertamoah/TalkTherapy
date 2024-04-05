<?php

namespace App\Services;

use App\Actions\Session\DeleteTherapyTopicAction;
use App\Actions\Session\EnsureCanCreateSessionAction;
use App\Actions\Session\EnsureCanUpdateTherapyTopicAction;
use App\Actions\Session\EnsureTherapyTopicExistsAction;
use App\Actions\Session\UpdateTherapyTopicAction;
use App\Actions\Star\CreateStarAction;
use App\Actions\Therapy\EnsureTherapyExistsAction;
use App\Actions\TherapyTopic\CreateTherapyTopicAction;
use App\DTOs\CreateStarDTO;
use App\DTOs\CreateTherapyTopicDTO;
use App\Enums\PaginationEnum;
use App\Enums\StarTypeEnum;
use App\Http\Resources\TherapyTopicResource;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;

class TherapyTopicService extends Service
{
    public function getTherapyTopics(Session $session, User $user)
    {
        if (
            $user->isNotAdmin() &&
            !$session->therapy->public &&
            $session->therapy->isNotParticipant($user)
        ) return [];
        
        return TherapyTopicResource::collection($session->topics()->latest()->paginate(
            PaginationEnum::preferencesPagination->value
        ));
    }

    public function createTherapyTopic(CreateTherapyTopicDTO $createTherapyTopicDTO)
    {
        EnsureTherapyExistsAction::new()->execute($createTherapyTopicDTO);

        EnsureCanCreateSessionAction::new()->execute($createTherapyTopicDTO);
        
        $topic = CreateTherapyTopicAction::new()->execute($createTherapyTopicDTO);

        CreateStarAction::new()->execute(
            CreateStarDTO::fromArray([
                'starredby' => null,
                'starred' => $createTherapyTopicDTO->user,
                'starreable' => $topic,
                'type' => StarTypeEnum::participation->value,
            ])
        );

        return $topic;
    }

    public function updateTherapyTopic(CreateTherapyTopicDTO $createTherapyTopicDTO)
    {
        EnsureTherapyTopicExistsAction::new()->execute($createTherapyTopicDTO);

        EnsureCanUpdateTherapyTopicAction::new()->execute($createTherapyTopicDTO);

        return UpdateTherapyTopicAction::new()->execute($createTherapyTopicDTO);
    }

    public function deleteTherapyTopic(CreateTherapyTopicDTO $createTherapyTopicDTO)
    {
        EnsureTherapyTopicExistsAction::new()->execute($createTherapyTopicDTO);

        EnsureCanUpdateTherapyTopicAction::new()->execute($createTherapyTopicDTO);

        return DeleteTherapyTopicAction::new()->execute($createTherapyTopicDTO);
    }

}