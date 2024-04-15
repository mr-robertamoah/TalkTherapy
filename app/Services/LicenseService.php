<?php

namespace App\Services;

use App\DTOs\CreateLicenseDTO;
use App\DTOs\FileUploadDTO;

class LicenseService extends Service
{
    public function createLicense(CreateLicenseDTO $createLicenseDTO)
    {
        $license = $createLicenseDTO->licensingAuthority->licenses()->create([
            'number' => $createLicenseDTO->number
        ]);

        $license->addedby()->associate($createLicenseDTO->addedby);

        $license->for()->associate($createLicenseDTO->for);

        $license->save();

        if ($createLicenseDTO->file) {
            $fileService = FileService::new();
            $data = $fileService->uploadFile(FileUploadDTO::new()->fromArray([
                'file' => $createLicenseDTO->file,
                'disk' => 'public',
                'path' => 'licenses'
            ]));

            $file = $fileService->saveFile(
                $createLicenseDTO->addedby, 
                $data
            );

            $license->files()->attach([$file->id]);
        }

        return $license->refresh();
    }
}