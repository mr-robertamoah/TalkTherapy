<?php

namespace App\Actions\Religion;

use App\Actions\Action;
use App\DTOs\CreateReligionDTO;

class CreateReligionAction extends Action
{
    public function execute(CreateReligionDTO $createReligionDTO) {
        $addedby = $createReligionDTO->addedby ? $createReligionDTO->addedby : $createReligionDTO->user;

        return $addedby->addedReligions()->create(
            $createReligionDTO->getData(true)
        );
    }
}