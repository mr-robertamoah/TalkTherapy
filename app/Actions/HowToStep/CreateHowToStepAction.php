<?php

namespace App\Actions\HowToStep;

use App\Actions\Action;
use App\DTOs\CreateHowToStepDTO;
use App\DTOs\FileUploadDTO;
use App\Services\FileService;

class CreateHowToStepAction extends Action
{
    public function execute(CreateHowToStepDTO $createHowToStepDTO)
    {
        $howToStep = $createHowToStepDTO->user->addedHowToSteps()->create([
            'name' => $createHowToStepDTO->name,
            'description' => $createHowToStepDTO->description,
            'position' => $createHowToStepDTO->position,
            'element_id' => $createHowToStepDTO->elementId,
            'how_to_id' => $createHowToStepDTO->howTo->id,
        ]);

        $fileService = FileService::new();
        $fileData = $fileService->uploadFile(
            FileUploadDTO::new()->fromArray([
                'file' => $createHowToStepDTO->file,
                'path' => 'others'
            ])
        );

        if ($fileData) {
            $file = $fileService->saveFile($createHowToStepDTO->user, $fileData);
    
            $howToStep->file_id = $file->id;
        }
        
        $howToStep->save();

        return $howToStep;
    }
}