<?php

namespace App\Actions\Comment;

use App\Actions\Action;
use App\DTOs\CreateCommentDTO;
use App\Exceptions\CommentException;

class EnsureCanCreateCommentAction extends Action
{
    public function execute(CreateCommentDTO $createCommentDTO)
    {
        if ($createCommentDTO->user) return;

        throw new CommentException("You cannot comment when not a user. Please try logging in first.", 422);
    }
}