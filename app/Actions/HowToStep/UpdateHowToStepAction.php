<?php

namespace App\Actions\HowToStep;

use App\Actions\Action;
use App\DTOs\CreateHowToStepDTO;
use App\DTOs\FileUploadDTO;
use App\Services\FileService;

class UpdateHowToStepAction extends Action
{
    private array $data = [];

    public function execute(CreateHowToStepDTO $createHowToStepDTO)
    {
        $this->setData($createHowToStepDTO);

        $createHowToStepDTO->howToStep->update($this->data);

        if (!$createHowToStepDTO->file)
            return $createHowToStepDTO->howToStep->refresh();

        $fileService = FileService::new();
        $fileService->deleteFile($createHowToStepDTO->howToStep->file);
        $fileData = $fileService->uploadFile(
            FileUploadDTO::new()->fromArray([
                'file' => $createHowToStepDTO->file,
                'path' => 'others'
            ])
        );
        $file = $fileService->saveFile($createHowToStepDTO->user, $fileData);

        $createHowToStepDTO->howToStep->file_id = $file->id;
        $createHowToStepDTO->howToStep->save();

        return $createHowToStepDTO->howToStep->refresh();
    }

    private function setData(CreateHowToStepDTO $createHowToStepDTO)
    {
        $this->setValueOnData('name', $createHowToStepDTO);
        $this->setValueOnData('description', $createHowToStepDTO);
        $this->setValueOnData('position', $createHowToStepDTO);
    }
    
    private function setValueOnData(
        String $dataKey,
        CreateHowToStepDTO $createHowToStepDTO,
        String|null $objectKey = null
    ) {
        $objectKey = $objectKey ?: $dataKey;

        if (
            !is_null($createHowToStepDTO->$objectKey) &&
            $createHowToStepDTO->$objectKey !== $createHowToStepDTO->howToStep->$dataKey
        )        
            $this->data[$dataKey] = $createHowToStepDTO->$objectKey;
    }
}