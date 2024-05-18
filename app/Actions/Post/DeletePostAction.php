<?php

namespace App\Actions\Post;

use App\Actions\Action;
use App\DTOs\CreatePostDTO;
use App\Services\FileService;

class DeletePostAction extends Action
{
    public function execute(CreatePostDTO $createPostDTO)
    {
        if ($createPostDTO->postable) {
            $createPostDTO->post->postable()->dissociate();
            $createPostDTO->post->save();
        }

        $fileService = FileService::new();
        $files = [];

        foreach ($createPostDTO->post->files as $file) {
            $files[] = $file->id;
            
            $fileService->deleteFile($file);
        }

        $createPostDTO->post->files()->detach($files);

        $createPostDTO->post->starreable()->delete();

        return $createPostDTO->post->delete();
    }
}