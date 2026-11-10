<?php

namespace App\Actions\Comment;

use App\Actions\Action;
use App\DTOs\CreateCommentDTO;
use App\Models\Comment;
use App\Models\GroupTherapy;
use App\Models\Therapy;

class CreateCommentAction extends Action
{
    public function execute(CreateCommentDTO $createCommentDTO): Comment
    {
        $comment = $createCommentDTO->user->comments()->create([
            'content' => $createCommentDTO->content,
        ]);

        $comment->commentable()->associate($createCommentDTO->commentable);
        // SCRUM-354: snapshot whether the author is one of this therapy's anonymous people, so a
        // later change (leaving the group, anonymity switched off) never unmasks this comment.
        $commentable = $createCommentDTO->commentable;
        $comment->anonymous = ($commentable instanceof Therapy || $commentable instanceof GroupTherapy)
            && $commentable->isAnonymousParticipant($createCommentDTO->user);
        $comment->save();

        return $comment;
    }
}
