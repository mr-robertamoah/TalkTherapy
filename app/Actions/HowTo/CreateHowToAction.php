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
        // SCRUM-379 (found by a review test): a closure, so ANY exception rolls back. The old manual
        // beginTransaction()/commit() left the transaction open when a step failed (e.g. a step id
        // from another how-to), so the rest of that request ran inside a transaction never committed.
        return DB::transaction(function () use ($createHowToDTO) {

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

            return $howTo->refresh()->load('howToSteps.stepFile'); // SCRUM-380: no lazy load per step in the response
        });
    }
}
