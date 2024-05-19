<?php

namespace App\Actions\Comment;

use App\Actions\Action;
use App\DTOs\CreateCommentDTO;

class DeleteCommentAction extends Action
{
    public function execute(CreateCommentDTO $createCommentDTO)
    {
        return $createCommentDTO->comment->delete();
    }
}