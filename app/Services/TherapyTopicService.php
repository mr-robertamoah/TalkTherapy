<?php

namespace App\Services;

use App\Actions\TherapyTopic\DeleteTherapyTopicAction;
use App\Actions\TherapyTopic\EnsureCanCreateTherapyTopicAction;
use App\Actions\TherapyTopic\EnsureCanUpdateTherapyTopicAction;
use App\Actions\TherapyTopic\EnsureTherapyTopicExistsAction;
use App\Actions\TherapyTopic\UpdateTherapyTopicAction;
use App\Actions\Star\CreateStarAction;
use App\Actions\Therapy\EnsureTherapyExistsAction;
use App\Actions\TherapyTopic\CreateTherapyTopicAction;
use App\Actions\TherapyTopic\EnsureDataIsValidAction;
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
    public function getTherapyTopics(Therapy $therapy, User $user, String|null $name)
    {
        if (
            $user->isNotAdmin() &&
            !$therapy->public &&
            $therapy->isNotParticipant($user)
        ) return [];
        
        $query = $therapy->topics()->when($name, function($query) use ($name) {
            $query->whereNameLike($name);
        });

        return TherapyTopicResource::collection($query->latest()->paginate(
            PaginationEnum::preferencesPagination->value
        ));
    }

    public function createTherapyTopic(CreateTherapyTopicDTO $createTherapyTopicDTO)
    {
        EnsureTherapyExistsAction::new()->execute($createTherapyTopicDTO);

        EnsureCanCreateTherapyTopicAction::new()->execute($createTherapyTopicDTO);

        EnsureDataIsValidAction::new()->execute($createTherapyTopicDTO);
        
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
        
        EnsureDataIsValidAction::new()->execute($createTherapyTopicDTO);

        return UpdateTherapyTopicAction::new()->execute($createTherapyTopicDTO);
    }

    public function deleteTherapyTopic(CreateTherapyTopicDTO $createTherapyTopicDTO)
    {
        EnsureTherapyTopicExistsAction::new()->execute($createTherapyTopicDTO);

        EnsureCanUpdateTherapyTopicAction::new()->execute($createTherapyTopicDTO);

        return DeleteTherapyTopicAction::new()->execute($createTherapyTopicDTO);
    }

}