<?php

namespace App\Actions\Report;

use App\Actions\Action;
use App\DTOs\CreateReportDTO;
use App\DTOs\FileUploadDTO;
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
                        // SCRUM-337: private -- never the public others/ folder.
                        'disk' => 'report_evidence',
                        'path' => 'reports',
                    ])
                );

                $files[] = $fileService->saveFile($createReportDTO->user, $fileData);
            }

            $createReportDTO->report->files()->attach(array_map(fn ($f) => $f->id, $files));
        }

        if ($createReportDTO->deletedFiles) {
            $files = [];

            // SCRUM-337: only this report's own files -- any File id used to be accepted, letting a
            // reporter delete anyone's file (the SCRUM-195 message bug, here too).
            $ownFiles = $createReportDTO->report->files()->whereIn('files.id', $createReportDTO->deletedFiles)->get();

            foreach ($ownFiles as $file) {
                $fileService->deleteFile($file);

                $files[] = $file->id;
            }

            $createReportDTO->report->files()->detach($files);
        }

        return $createReportDTO->report->refresh();
    }

    private function setData(CreateReportDTO $createReportDTO)
    {
        if ($createReportDTO->description && $createReportDTO->description !== $createReportDTO->report->description) {
            $this->data['description'] = $createReportDTO->description;
        }

        if ($createReportDTO->data) {
            $this->data['data'] = array_merge($createReportDTO->report->data ?: [], $createReportDTO->data);
        }
    }
}
