<?php

namespace App\Actions\Profession;

use App\Actions\Action;
use App\DTOs\CreateProfessionDTO;

class CreateProfessionAction extends Action
{
    public function execute(CreateProfessionDTO $createProfessionDTO) {
        $addedby = $createProfessionDTO->addedby ? $createProfessionDTO->addedby : $createProfessionDTO->user;

        return $addedby->addedProfessions()->create(
            $createProfessionDTO->getData(true)
        );
    }
}