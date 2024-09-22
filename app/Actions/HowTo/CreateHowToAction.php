<?php

namespace App\Actions\HowTo;

use App\Actions\Action;
use App\Actions\HowToStep\CreateHowToStepAction;
use App\DTOs\CreateHowToDTO;
use App\DTOs\CreateHowToStepDTO;
use Illuminate\Support\Facades\DB;

class CreateHowToAction extends Action
{
    public function execute(CreateHowToDTO $createHowToDTO)
    {
        DB::beginTransaction();
        
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
                    'name' => getArrayKey('name', $howToStepData),
                    'description' => getArrayKey('description', $howToStepData),
                    'position' => getArrayKey('position', $howToStepData),
                    'file' => getArrayKey('file', $howToStepData),
                    'elementId' => getArrayKey('elementId', $howToStepData),
                ])
            );
        }

        DB::commit();

        return $howTo->refresh();
    }
}