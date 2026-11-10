<?php

namespace App\Actions\Post;

use App\Actions\Action;
use App\DTOs\CreatePostDTO;
use App\DTOs\FileUploadDTO;
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
                        'path' => 'others',
                    ])
                );

                $files[] = $fileService->saveFile($createPostDTO->user, $fileData);
            }

            $createPostDTO->post->files()->attach(array_map(fn ($f) => $f->id, $files));
        }

        if ($createPostDTO->deletedFiles) {
            $files = [];

            // Security review (SCRUM-337): only this post's own files -- any File id used to be
            // accepted, so a post author could delete anyone's file, report evidence included.
            $ownFiles = $createPostDTO->post->files()->whereIn('files.id', $createPostDTO->deletedFiles)->get();

            foreach ($ownFiles as $file) {
                $fileService->deleteFile($file);

                $files[] = $file->id;
            }

            $createPostDTO->post->files()->detach($files);
        }

        return $createPostDTO->post->refresh();
    }

    private function setData(CreatePostDTO $createPostDTO)
    {
        if ($createPostDTO->content !== $createPostDTO->post->content) {
            $this->data['content'] = $createPostDTO->content;
        }
    }
}
