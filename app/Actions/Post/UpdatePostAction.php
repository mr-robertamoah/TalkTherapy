<?php

namespace App\Actions\Post;

use App\Actions\Action;
use App\DTOs\CreatePostDTO;
use App\DTOs\FileUploadDTO;
use App\Models\File;
use App\Services\FileService;

class UpdatePostAction extends Action
{
    private array $data = [];

    public function execute(CreatePostDTO $createPostDTO)
    {
        $this->setData($createPostDTO);

        $createPostDTO->post->update($this->data);

        if ($createPostDTO->postable) {
            $createPostDTO->post->postable()->dissociate();
            $createPostDTO->post->postable()->associate($createPostDTO->postable);
            $createPostDTO->post->save();
        }

        $fileService = FileService::new();

        if ($createPostDTO->files) {
            $files = [];

            foreach ($createPostDTO->files as $uploadedFile) {
                $fileData = $fileService->uploadFile(
                    FileUploadDTO::new()->fromArray([
                        'file' => $uploadedFile,
                        'path' => 'others'
                    ])
                );

                $files[] = $fileService->saveFile($createPostDTO->user, $fileData);
            }

            $createPostDTO->post->files()->attach(array_map(fn ($f) => $f->id, $files));
        }
        
        if ($createPostDTO->deletedFiles) {
            $files = [];

            foreach ($createPostDTO->deletedFiles as $fileId) {
                $fileData = $fileService->deleteFile(File::find($fileId));

                $files[] = $fileId;
            }

            $createPostDTO->post->files()->detach($files);
        }

        return $createPostDTO->post->refresh();
    }

    private function setData(CreatePostDTO $createPostDTO)
    {
        if ($createPostDTO->content && $createPostDTO->content !== $createPostDTO->post->content)
            $this->data['content'] = $createPostDTO->content;
    }
}