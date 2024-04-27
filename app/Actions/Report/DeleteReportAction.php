<?php

namespace App\Actions\Report;

use App\Actions\Action;
use App\DTOs\CreateReportDTO;
use App\Services\FileService;

class DeleteReportAction extends Action
{
    public function execute(CreateReportDTO $createReportDTO)
    {
        if ($createReportDTO->reportable) {
            $createReportDTO->report->reportable()->dissociate();
            $createReportDTO->report->save();
        }

        $fileService = FileService::new();
        $files = [];

        foreach ($createReportDTO->report->files as $file) {
            $files[] = $file->id;
            
            $fileService->deleteFile($file);
        }

        $createReportDTO->report->files()->detach($files);

        return $createReportDTO->report->delete();
    }
}