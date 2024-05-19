<?php

namespace App\Actions\Comment;

use App\Actions\Action;
use App\DTOs\CreateCommentDTO;
use App\Exceptions\CommentException;

class EnsureCommentDataIsValidAction extends Action
{
    public function execute(CreateCommentDTO $createCommentDTO)
    {
        if ($createCommentDTO->content) return;

        throw new CommentException("Content of the comment is required to create a comment.", 422);
    }
}