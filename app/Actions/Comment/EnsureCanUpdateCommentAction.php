<?php

namespace App\Actions\Comment;

use App\Actions\Action;
use App\DTOs\CreateCommentDTO;
use App\Exceptions\CommentException;

class EnsureCanUpdateCommentAction extends Action
{
    public function execute(CreateCommentDTO $createCommentDTO)
    {
        if (
            $createCommentDTO->user &&
            $createCommentDTO->user->is($createCommentDTO->comment->user)
        ) return;

        throw new CommentException("You are not the owner of the comment.", 422);
    }
}