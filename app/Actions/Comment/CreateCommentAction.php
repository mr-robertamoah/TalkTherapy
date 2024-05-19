<?php

namespace App\Actions\Comment;

use App\Actions\Action;
use App\DTOs\CreateCommentDTO;
use App\Models\Comment;

class CreateCommentAction extends Action
{
    public function execute(CreateCommentDTO $createCommentDTO) : Comment
    {
        $comment = $createCommentDTO->user->comments()->create([
            'content' => $createCommentDTO->content
        ]);

        $comment->commentable()->associate($createCommentDTO->commentable);
        $comment->save();
        
        return $comment;
    }
}