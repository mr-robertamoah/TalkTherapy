<?php

namespace App\Actions\Comment;

use App\Actions\Action;
use App\DTOs\CreateCommentDTO;
use App\Exceptions\CommentException;
use App\Models\GroupTherapy;
use App\Models\Therapy;

class EnsureCommentableExistsAction extends Action
{
    public const NOT_AVAILABLE = 'Comments are not available here.';

    public function execute(CreateCommentDTO $createCommentDTO)
    {
        // SCRUM-351/354: a therapy or group must be public -- for reading its comments too. A private
        // one answers exactly like a missing one, so its existence can't be probed.
        $commentable = $createCommentDTO->commentable;
        if ($commentable && (! ($commentable instanceof Therapy || $commentable instanceof GroupTherapy) || $commentable->acceptsPublicEngagement())) {
            return;
        }

        throw new CommentException(self::NOT_AVAILABLE, 422);
    }
}
