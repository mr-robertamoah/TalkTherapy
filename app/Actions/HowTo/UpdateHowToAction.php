<?php

namespace App\Actions\HowTo;

use App\Actions\Action;
use App\Actions\HowToStep\CreateHowToStepAction;
use App\Actions\HowToStep\DeleteHowToStepAction;
use App\Actions\HowToStep\UpdateHowToStepAction;
use App\DTOs\CreateHowToDTO;
use App\DTOs\CreateHowToStepDTO;
use Illuminate\Support\Facades\DB;

class UpdateHowToAction extends Action
{
    private array $data = [];

    public function execute(CreateHowToDTO $createHowToDTO)
    {
        // SCRUM-379 (found by a review test): a closure, so ANY exception rolls back. The old manual
        // beginTransaction()/commit() left the transaction open when a step failed (e.g. a step id
        // from another how-to), so the rest of that request ran inside a transaction never committed.
        return DB::transaction(function () use ($createHowToDTO) {

            $this->setData($createHowToDTO);

            $createHowToDTO->howTo->update($this->data);

            // SCRUM-379 (security review): steps are looked up within THIS how-to, so editing one can't
            // replace or delete another how-to's steps (and their files); an unknown id is a 404.
            foreach ($createHowToDTO->howToSteps as $howToStepData) {
                UpdateHowToStepAction::new()->execute(
                    CreateHowToStepDTO::new()->fromArray([
                        'howToStep' => $createHowToDTO->howTo->howToSteps()->findOrFail($howToStepData['id']),
                        'user' => $createHowToDTO->user,
                        'name' => getArrayKey('name', $howToStepData),
                        'description' => getArrayKey('description', $howToStepData),
                        'position' => getArrayKey('position', $howToStepData),
                        'elementId' => getArrayKey('elementId', $howToStepData),
                        'file' => getArrayKey('file', $howToStepData),
                    ])
                );
            }

            foreach ($createHowToDTO->addedHowToSteps as $howToStepData) {
                CreateHowToStepAction::new()->execute(
                    CreateHowToStepDTO::new()->fromArray([
                        'howTo' => $createHowToDTO->howTo,
                        'user' => $createHowToDTO->user,
                        'name' => getArrayKey('name', $howToStepData),
                        'description' => getArrayKey('description', $howToStepData),
                        'position' => getArrayKey('position', $howToStepData),
                        'elementId' => getArrayKey('elementId', $howToStepData),
                        'file' => getArrayKey('file', $howToStepData),
                    ])
                );
            }

            foreach ($createHowToDTO->deletedHowToSteps as $howToStepData) {
                DeleteHowToStepAction::new()->execute(
                    CreateHowToStepDTO::new()->fromArray([
                        'howToStep' => $createHowToDTO->howTo->howToSteps()->findOrFail($howToStepData['id']),
                    ])
                );
            }

            return $createHowToDTO->howTo->refresh()->load('howToSteps.stepFile'); // SCRUM-380: no lazy load per step in the response
        });
    }

    private function setData(CreateHowToDTO $createHowToDTO)
    {
        $this->setValueOnData('name', $createHowToDTO);
        $this->setValueOnData('description', $createHowToDTO);
        $this->setValueOnData('page', $createHowToDTO);
    }

    private function setValueOnData(
        string $dataKey,
        CreateHowToDTO $createHowToDTO,
        ?string $objectKey = null
    ) {
        $objectKey = $objectKey ?: $dataKey;

        if (
            ! is_null($createHowToDTO->$objectKey) &&
            $createHowToDTO->$objectKey !== $createHowToDTO->howTo->$dataKey
        ) {
            $this->data[$dataKey] = $createHowToDTO->$objectKey;
        }
    }
}
