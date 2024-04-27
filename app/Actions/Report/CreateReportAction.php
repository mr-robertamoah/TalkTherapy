<?php

namespace App\Actions\Report;

use App\Actions\Action;
use App\DTOs\CreateReportDTO;
use App\DTOs\FileUploadDTO;
use App\Services\FileService;

class CreateReportAction extends Action
{
    public function execute(CreateReportDTO $createReportDTO)
    {
        $report = $createReportDTO->addedby->addedReports()->create([
            'description' => $createReportDTO->description,
            'data' => $createReportDTO->data
        ]);

        $report->reportable()->associate($createReportDTO->reportable);
        $report->save();

        ds($createReportDTO);
        if (!$createReportDTO->files)
            return $report->refresh();

        $fileService = FileService::new();
        $files = [];

        foreach ($createReportDTO->files as $uploadedFile) {
            $fileData = $fileService->uploadFile(
                FileUploadDTO::new()->fromArray([
                    'file' => $uploadedFile,
                    'path' => 'others'
                ])
            );

            $files[] = $fileService->saveFile($createReportDTO->addedby, $fileData);
        }

        $report->files()->attach(array_map(fn($f) => $f->id, $files));

        return $report->refresh();
    }
}