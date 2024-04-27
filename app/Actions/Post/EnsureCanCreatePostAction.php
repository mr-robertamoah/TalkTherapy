<?php

namespace App\Actions\Post;

use App\Actions\Action;
use App\DTOs\CreatePostDTO;
use App\Exceptions\PostException;
use App\Models\Counsellor;

class EnsureCanCreatePostAction extends Action
{
    public function execute(CreatePostDTO $createPostDTO)
    {
        if (
            $createPostDTO->user->isAdmin() ||
            ($createPostDTO->addedby::class == Counsellor::class && $createPostDTO->addedby->isVerified())
        ) return;

        throw new PostException("You are not allowed to create a post with the account provided.", 422);
    }
}