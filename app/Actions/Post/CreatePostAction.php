<?php

namespace App\Actions\Post;

use App\Actions\Action;
use App\DTOs\CreatePostDTO;
use App\DTOs\FileUploadDTO;
use App\Models\Counsellor;
use App\Services\FileService;

class CreatePostAction extends Action
{
    public function execute(CreatePostDTO $createPostDTO)
    {
        $post = $createPostDTO->addedby->addedPosts()->create([
            'content' => $createPostDTO->content
        ]);

        if ($createPostDTO->postable) {
            $post->postable()->associate($createPostDTO->postable);
            $post->save();
        }

        if (!$createPostDTO->files)
            return $post->refresh();

        $fileService = FileService::new();
        $files = [];

        foreach ($createPostDTO->files as $uploadedFile) {
            $fileData = $fileService->uploadFile(
                FileUploadDTO::new()->fromArray([
                    'file' => $uploadedFile,
                    'path' => 'others'
                ])
            );

            $files[] = $fileService->saveFile($createPostDTO->addedby, $fileData);
        }

        $post->files()->attach(array_map(fn($f) => $f->id, $files));

        return $post->refresh();
    }
}