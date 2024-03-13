<?php

namespace App\Actions\Star;
use App\Actions\Action;
use App\DTOs\CreateStarDTO;
use App\Exceptions\MinimumModelsForStarDoesNotExistException;

class EnsureMinimumModelsForStarExistsAction extends Action
{
    public function execute(CreateStarDTO $createStarDTO)
    {
        if ($createStarDTO->starreable && $createStarDTO->starred) return;

        throw new MinimumModelsForStarDoesNotExistException('Enough data was not provided.', 422);
    }
}