<?php

namespace App\Actions\HowTo;

use App\Actions\Action;
use App\Actions\HowToStep\CreateHowToStepAction;
use App\Actions\HowToStep\DeleteHowToStepAction;
use App\Actions\HowToStep\UpdateHowToStepAction;
use App\DTOs\CreateHowToDTO;
use App\DTOs\CreateHowToStepDTO;
use App\Models\HowToStep;

class UpdateHowToAction extends Action
{
    private array $data = [];

    public function execute(CreateHowToDTO $createHowToDTO)
    {
        $this->setData($createHowToDTO);
        
        $createHowToDTO->howTo->update($this->data);

        foreach ($createHowToDTO->howToSteps as $howToStepData) {
            UpdateHowToStepAction::new()->execute(
                CreateHowToStepDTO::new()->fromArray([
                    'howToStep' => HowToStep::find($howToStepData['id']),
                    'user' => $createHowToDTO->user,
                    'name' => $howToStepData['name'],
                    'description' => $howToStepData['description'],
                    'position' => $howToStepData['position'],
                    'file' => $howToStepData['file'],
                ])
            );
        }

        foreach ($createHowToDTO->addedHowToSteps as $howToStepData) {
            CreateHowToStepAction::new()->execute(
                CreateHowToStepDTO::new()->fromArray([
                    'howTo' => $createHowToDTO->howTo,
                    'user' => $createHowToDTO->user,
                    'name' => $howToStepData['name'],
                    'description' => $howToStepData['description'],
                    'position' => $howToStepData['position'],
                    'file' => $howToStepData['file'],
                ])
            );
        }

        foreach ($createHowToDTO->deletedHowToSteps as $howToStepData) {
            DeleteHowToStepAction::new()->execute(
                CreateHowToStepDTO::new()->fromArray([
                    'howToStep' => HowToStep::find($howToStepData['id']),
                ])
            );
        }

        return $createHowToDTO->howTo->refresh();
    }

    private function setData(CreateHowToDTO $createHowToDTO)
    {
        $this->setValueOnData('name', $createHowToDTO);
        $this->setValueOnData('description', $createHowToDTO);
        $this->setValueOnData('page', $createHowToDTO);
    }
    
    private function setValueOnData(
        String $dataKey,
        CreateHowToDTO $createHowToDTO,
        String|null $objectKey = null
    ) {
        $objectKey = $objectKey ?: $dataKey;

        if (
            !is_null($createHowToDTO->$objectKey) &&
            $createHowToDTO->$objectKey !== $createHowToDTO->howTo->$dataKey
        )        
            $this->data[$dataKey] = $createHowToDTO->$objectKey;
    }
}