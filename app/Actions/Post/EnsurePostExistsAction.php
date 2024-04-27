<?php

namespace App\Actions\Post;

use App\Actions\Action;
use App\DTOs\CreatePostDTO;
use App\Exceptions\PostException;
use App\Models\Counsellor;

class EnsurePostExistsAction extends Action
{
    public function execute(CreatePostDTO $createPostDTO)
    {
        if (
            $createPostDTO->post
        ) return;

        throw new PostException("You cannot perform this action because post was not found.", 422);
    }
}