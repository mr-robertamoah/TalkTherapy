<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\DTOs\FileUploadDTO;
use App\DTOs\UpdateUserAvatarDTO;
use App\Models\User;
use App\Services\FileService;

class UpdateUserAvatarAction extends Action
{
    public function execute(UpdateUserAvatarDTO $dto): User
    {
        $fileService = FileService::new();

        if ($dto->avatar) {
            $oldAvatar = $dto->user->avatar;

            $fileData = $fileService->uploadFile(FileUploadDTO::new()->fromArray([
                'disk' => 'public',
                'path' => 'avatars',
                'file' => $dto->avatar,
            ]));

            $file = $fileService->saveFile($dto->user, $fileData);
            $dto->user->avatarFile()->sync([$file->id]);

            if ($oldAvatar) {
                $fileService->deleteFile($oldAvatar);
            }
        } elseif ($dto->deleteAvatar && $dto->user->avatar) {
            $oldAvatar = $dto->user->avatar;
            $dto->user->avatarFile()->sync([]);
            $fileService->deleteFile($oldAvatar);
        }

        return $dto->user->refresh();
    }
}
