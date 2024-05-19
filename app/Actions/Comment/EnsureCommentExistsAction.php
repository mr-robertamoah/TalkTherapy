<?php

namespace App\Actions\Comment;

use App\Actions\Action;
use App\DTOs\CreateCommentDTO;
use App\Exceptions\CommentException;

class EnsureCommentExistsAction extends Action
{
    public function execute(CreateCommentDTO $createCommentDTO)
    {
        if ($createCommentDTO->comment) return;

        throw new CommentException("Comment was not found.", 422);
    }
}