<?php

namespace App\Actions\Post;

use App\Actions\Action;
use App\DTOs\CreatePostDTO;
use App\Exceptions\PostException;
use App\Models\Counsellor;

class EnsureCanUpdatePostAction extends Action
{
    public function execute(CreatePostDTO $createPostDTO)
    {
        if (
            $createPostDTO->user->isAdmin() ||
            $createPostDTO->user->is($createPostDTO->post->addedby) ||
            (
                $createPostDTO->post->addedby::class == Counsellor::class && 
                $createPostDTO->post->addedby->user->is($createPostDTO->user)
            )
        ) return;

        throw new PostException("You are not allowed to update/delete this post.", 422);
    }
}