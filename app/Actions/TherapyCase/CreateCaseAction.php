<?php

namespace App\Actions\TherapyCase;

use App\Actions\Action;
use App\DTOs\CreateCaseDTO;

class CreateCaseAction extends Action
{
    public function execute(CreateCaseDTO $createCaseDTO) {
        $addedby = $createCaseDTO->addedby ? $createCaseDTO->addedby : $createCaseDTO->user;

        return $addedby->addedTherapyCases()->create(
            $createCaseDTO->getData(true)
        );
    }
}