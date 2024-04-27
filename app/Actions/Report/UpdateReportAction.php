<?php

namespace App\Actions\Report;

use App\Actions\Action;
use App\DTOs\CreateReportDTO;
use App\DTOs\FileUploadDTO;
use App\Models\File;
use App\Services\FileService;

class UpdateReportAction extends Action
{
    private array $data = [];

    public function execute(CreateReportDTO $createReportDTO)
    {
        $this->setData($createReportDTO);

        $createReportDTO->report->update($this->data);

        if ($createReportDTO->reportable) {
            $createReportDTO->report->reportable()->dissociate();
            $createReportDTO->report->reportable()->associate($createReportDTO->reportable);
            $createReportDTO->report->save();
        }

        $fileService = FileService::new();

        if ($createReportDTO->files) {
            $files = [];

            foreach ($createReportDTO->files as $uploadedFile) {
                $fileData = $fileService->uploadFile(
                    FileUploadDTO::new()->fromArray([
                        'file' => $uploadedFile,
                        'path' => 'others'
                    ])
                );

                $files[] = $fileService->saveFile($createReportDTO->user, $fileData);
            }

            $createReportDTO->report->files()->attach(array_map(fn ($f) => $f->id, $files));
        }
        
        if ($createReportDTO->deletedFiles) {
            $files = [];

            foreach ($createReportDTO->deletedFiles as $fileId) {
                $fileData = $fileService->deleteFile(File::find($fileId));

                $files[] = $fileId;
            }

            $createReportDTO->report->files()->detach($files);
        }

        return $createReportDTO->report->refresh();
    }

    private function setData(CreateReportDTO $createReportDTO)
    {
        if ($createReportDTO->description && $createReportDTO->description !== $createReportDTO->report->description)
            $this->data['description'] = $createReportDTO->description;

        if ($createReportDTO->data)
            $this->data['data'] = array_merge($createReportDTO->report->data ?: [], $createReportDTO->data);
    }
}