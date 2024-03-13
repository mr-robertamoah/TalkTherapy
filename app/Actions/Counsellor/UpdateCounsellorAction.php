<?php

namespace App\Actions\Counsellor;
use App\Actions\Action;
use App\DTOs\FileUploadDTO;
use App\DTOs\UpdateCounsellorDTO;
use App\Services\FileService;

class UpdateCounsellorAction extends Action
{
    public function execute(UpdateCounsellorDTO $updateCounsellorDTO)
    {
        $data = [];

        $fileService = FileService::new();

        if ($updateCounsellorDTO->cover) {
            $data = $fileService->uploadFile(FileUploadDTO::new()->fromArray([
                'disk' => 'public',
                'path' => 'covers',
                'file' => $updateCounsellorDTO->cover
            ]));

            $file = $fileService->saveFile($updateCounsellorDTO->user, $data);
            $data['cover_id'] = $file->id;
        }

        if ($updateCounsellorDTO->avatar) {
            // TODO resize image
            $data = $fileService->uploadFile(FileUploadDTO::new()->fromArray([
                'disk' => 'public',
                'path' => 'avatars',
                'file' => $updateCounsellorDTO->avatar
            ]));

            $file = $fileService->saveFile($updateCounsellorDTO->user, $data);
            $data['avatar_id'] = $file->id;
        }

        if (
            ($updateCounsellorDTO->deleteAvatar || $updateCounsellorDTO->avatar) && 
            $updateCounsellorDTO->counsellor->avatar
        ) {
            $fileService->deleteFile($updateCounsellorDTO->counsellor->avatar);
        }

        if (
            ($updateCounsellorDTO->deleteCover || $updateCounsellorDTO->cover) && 
            $updateCounsellorDTO->counsellor->cover
        ) {
            $fileService->deleteFile($updateCounsellorDTO->counsellor->cover);
        }

        if (!is_null($updateCounsellorDTO->contactVisible)) $data['contact_visible'] = $updateCounsellorDTO->contactVisible;
        if ($updateCounsellorDTO->name) $data['name'] = $updateCounsellorDTO->name;
        if ($updateCounsellorDTO->about) $data['about'] = $updateCounsellorDTO->about;
        if ($updateCounsellorDTO->email) $data['email'] = $updateCounsellorDTO->email; // TODO request email verification
        if ($updateCounsellorDTO->phone) $data['phone'] = $updateCounsellorDTO->phone;
        if ($updateCounsellorDTO->professionId) $data['profession_id'] = $updateCounsellorDTO->professionId;

        if ($updateCounsellorDTO->selectedCases) {
            $updateCounsellorDTO->counsellor->cases()->sync($updateCounsellorDTO->selectedCases);
        }
        if ($updateCounsellorDTO->selectedLanguages) {
            $updateCounsellorDTO->counsellor->languages()->sync($updateCounsellorDTO->selectedLanguages);
        }
        if ($updateCounsellorDTO->selectedReligions) {
            $updateCounsellorDTO->counsellor->religions()->sync($updateCounsellorDTO->selectedReligions);
        }

        $updateCounsellorDTO->counsellor->update($data);

        return $updateCounsellorDTO->counsellor->refresh();
    }
}