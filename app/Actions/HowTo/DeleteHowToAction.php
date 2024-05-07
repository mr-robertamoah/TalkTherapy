<?php

namespace App\Actions\HowTo;

use App\Actions\Action;
use App\Actions\HowToStep\DeleteHowToStepAction;
use App\DTOs\CreateHowToDTO;
use App\DTOs\CreateHowToStepDTO;

class DeleteHowToAction extends Action
{
    public function execute(CreateHowToDTO $createHowToDTO)
    {
        $createHowToDTO->howTo->howToSteps->each(function($value, $key) {
            DeleteHowToStepAction::new()->execute(
                CreateHowToStepDTO::new()->fromArray([
                    'howToStep' => $value,
                ])
            );
        });

        return $createHowToDTO->howTo->delete();
    }
}