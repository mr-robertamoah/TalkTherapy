<?php

namespace App\Services;

use App\Actions\Star\CreateStarAction;
use App\Actions\Star\EnsureMinimumModelsForStarExistsAction;
use App\Actions\Star\EnsureStarTypeIsValidAction;
use App\DTOs\CreateStarDTO;
use App\Models\Star;

class StarService extends Service
{
    public function createStar(CreateStarDTO $createStarDTO): Star
    {
        EnsureStarTypeIsValidAction::new()->execute($createStarDTO->type);

        EnsureMinimumModelsForStarExistsAction::new()->execute($createStarDTO);

        return CreateStarAction::new()->execute($createStarDTO);
    }
}