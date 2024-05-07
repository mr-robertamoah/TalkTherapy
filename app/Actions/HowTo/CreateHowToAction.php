<?php

namespace App\Actions\HowTo;

use App\Actions\Action;
use App\Actions\HowToStep\CreateHowToStepAction;
use App\DTOs\CreateHowToDTO;
use App\DTOs\CreateHowToStepDTO;

class CreateHowToAction extends Action
{
    public function execute(CreateHowToDTO $createHowToDTO)
    {
        $howTo = $createHowToDTO->user->addedHowTos()->create([
            'name' => $createHowToDTO->name,
            'description' => $createHowToDTO->description,
            'page' => $createHowToDTO->page,
        ]);

        foreach ($createHowToDTO->howToSteps as $howToStepData) {
            CreateHowToStepAction::new()->execute(
                CreateHowToStepDTO::new()->fromArray([
                    'howTo' => $howTo,
                    'user' => $createHowToDTO->user,
                    'name' => $howToStepData['name'],
                    'description' => $howToStepData['description'],
                    'position' => $howToStepData['position'],
                    'file' => $howToStepData['file'],
                ])
            );
        }

        return $howTo->refresh();
    }
}