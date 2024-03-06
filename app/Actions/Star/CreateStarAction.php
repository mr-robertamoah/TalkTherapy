<?php

namespace App\Actions\Star;
use App\Actions\Action;
use App\Actions\Administrator\GetSuperAdministratorAction;
use App\DTOs\CreateStarDTO;
use App\Models\Star;

class CreateStarAction extends Action
{
    public function execute(CreateStarDTO $createStarDTO): Star
    {
        $star = Star::create(['type' => strtoupper($createStarDTO->type)]);

        $star->starreable()->associate($createStarDTO->starreable);

        $star->starred()->associate($createStarDTO->starred);

        $star->starredby()->associate(
            $createStarDTO->starredby
                ? $createStarDTO->starredby
                : GetSuperAdministratorAction::new()->execute()
        );

        return $star;
    }
}