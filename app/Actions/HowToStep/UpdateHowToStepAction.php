<?php

namespace App\Actions\HowToStep;

use App\Actions\Action;
use App\DTOs\CreateHowToStepDTO;
use App\DTOs\FileUploadDTO;
use App\Services\FileService;
use Illuminate\Support\Facades\DB;

class UpdateHowToStepAction extends Action
{
    private array $data = [];

    public function execute(CreateHowToStepDTO $createHowToStepDTO)
    {
        $this->setData($createHowToStepDTO);

        $createHowToStepDTO->howToStep->update($this->data);

        if (! $createHowToStepDTO->file) {
            return $createHowToStepDTO->howToStep->refresh();
        }

        $fileService = FileService::new();
        $oldFile = $createHowToStepDTO->howToStep->file;
        $fileData = $fileService->uploadFile(
            FileUploadDTO::new()->fromArray([
                'file' => $createHowToStepDTO->file,
                'path' => 'others',
            ])
        );
        $file = $fileService->saveFile($createHowToStepDTO->user, $fileData);

        // SCRUM-194: the pivot replaces the old file_id column; the old file goes only once the new
        // one is in place.
        $createHowToStepDTO->howToStep->stepFile()->sync([$file->id]);
        DB::afterCommit(fn () => $fileService->deleteFile($oldFile));

        return $createHowToStepDTO->howToStep->refresh()->load('stepFile');
    }

    private function setData(CreateHowToStepDTO $createHowToStepDTO)
    {
        $this->setValueOnData('name', $createHowToStepDTO);
        $this->setValueOnData('description', $createHowToStepDTO);
        $this->setValueOnData('position', $createHowToStepDTO);
        $this->setValueOnData('elementId', $createHowToStepDTO, arrayKey: 'element_id');
    }

    private function setValueOnData(
        string $dataKey,
        CreateHowToStepDTO $createHowToStepDTO,
        ?string $objectKey = null,
        ?string $arrayKey = null,
    ) {
        $objectKey = $objectKey ?: $dataKey;

        if (
            ! is_null($createHowToStepDTO->$objectKey) &&
            $createHowToStepDTO->$objectKey !== $createHowToStepDTO->howToStep->$dataKey
        ) {
            $this->data[$arrayKey ?: $dataKey] = $createHowToStepDTO->$objectKey;
        }
    }
}
