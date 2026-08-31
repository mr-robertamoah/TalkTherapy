<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\FileUploadDTO;
use App\DTOs\OrganizationDTO;
use App\Models\Organization;
use App\Services\FileService;

class UpdateOrganizationAction extends Action
{
    // Affiliation/membership-in-progress guards (blocking a role-flag toggle while
    // organization_counsellors/organization_members rows of that type exist) belong here once
    // TT-6.4a/TT-6.3 introduce those tables -- deliberately not built yet, this ticket only
    // has the org entity itself to guard against.
    public function execute(OrganizationDTO $dto): Organization
    {
        $organization = $dto->organization;

        $this->updateLogo($dto);

        foreach ([
            'name' => $dto->name,
            'legal_name' => $dto->legalName,
            'registration_number' => $dto->registrationNumber,
            'description' => $dto->description,
            'email' => $dto->email,
            'phone' => $dto->phone,
            'is_provider' => $dto->isProvider,
            'is_consumer' => $dto->isConsumer,
            'self_apply_enabled' => $dto->selfApplyEnabled,
        ] as $column => $value) {
            if (! is_null($value)) {
                $organization->{$column} = $value;
            }
        }

        $organization->save();

        return $organization->refresh();
    }

    private function updateLogo(OrganizationDTO $dto): void
    {
        $fileService = FileService::new();

        if ($dto->logo) {
            $oldLogo = $dto->organization->logo;

            $fileData = $fileService->uploadFile(FileUploadDTO::new()->fromArray([
                'disk' => 'public',
                'path' => 'logos',
                'file' => $dto->logo,
            ]));

            $file = $fileService->saveFile($dto->user, $fileData);
            $dto->organization->logoFile()->sync([$file->id]);

            if ($oldLogo) {
                $fileService->deleteFile($oldLogo);
            }
        } elseif ($dto->deleteLogo && $dto->organization->logo) {
            $oldLogo = $dto->organization->logo;
            $dto->organization->logoFile()->sync([]);
            $fileService->deleteFile($oldLogo);
        }
    }
}
