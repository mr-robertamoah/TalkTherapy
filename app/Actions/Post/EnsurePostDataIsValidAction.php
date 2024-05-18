<?php

namespace App\Actions\Post;

use App\Actions\Action;
use App\DTOs\CreatePostDTO;
use App\Exceptions\PostException;

class EnsurePostDataIsValidAction extends Action
{
    public function execute(CreatePostDTO $createPostDTO, string $action = 'create')
    {
        if (
            $createPostDTO->content ||
            $createPostDTO->files ||
            (
                $action == 'update' && 
                $createPostDTO->post->files()->whereNotIn('id', $createPostDTO->deletedFiles)->count()
            )
        ) return;

        throw new PostException("You have not provided enough data to {$action} post.", 422);
    }
}