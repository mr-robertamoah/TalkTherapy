<?php

namespace App\Actions\Comment;

use App\Actions\Action;
use App\DTOs\CreateCommentDTO;
use App\Exceptions\CommentException;

class EnsureCommentableExistsAction extends Action
{
    public function execute(CreateCommentDTO $createCommentDTO)
    {
        if ($createCommentDTO->commentable) return;

        throw new CommentException("You cannot comment without providing what you are commenting on.", 422);
    }
}