<?php

namespace App\Services;

use App\Actions\Comment\CreateCommentAction;
use App\Actions\Comment\DeleteCommentAction;
use App\Actions\Comment\EnsureCanCreateCommentAction;
use App\Actions\Comment\EnsureCanUpdateCommentAction;
use App\Actions\Comment\EnsureCommentableExistsAction;
use App\Actions\Comment\EnsureCommentDataIsValidAction;
use App\Actions\Comment\EnsureCommentExistsAction;
use App\Actions\Guardianship\EnsureMinorHasActiveGuardianAction;
use App\Actions\Post\SyncPostInteractionStarAction;
use App\DTOs\CreateCommentDTO;
use App\Enums\PaginationEnum;
use App\Http\Resources\CommentResource;
use App\Models\GroupTherapy;
use App\Models\Therapy;

class CommentService extends Service
{
    public function createComment(CreateCommentDTO $createCommentDTO)
    {
        EnsureCanCreateCommentAction::new()->execute($createCommentDTO);

        EnsureCommentableExistsAction::new()->execute($createCommentDTO);

        // SCRUM-288: commenting on a therapy you take part in is a write in it, refused while it is
        // paused for a missing guardian (for the 1:1's counsellor too). Posts, and other people's
        // public therapies, aren't part of the paused therapy.
        $commentable = $createCommentDTO->commentable;
        if (($commentable instanceof Therapy || $commentable instanceof GroupTherapy) && $createCommentDTO->user && $commentable->isParticipant($createCommentDTO->user)) {
            EnsureMinorHasActiveGuardianAction::new()->execute($createCommentDTO->user, $commentable);
        }

        EnsureCommentDataIsValidAction::new()->execute($createCommentDTO);

        $comment = CreateCommentAction::new()->execute($createCommentDTO);

        // TT-7.11c/SCRUM-472: a comment (anonymous or not) on a counsellor's post may earn them a star. Never blocks it.
        SyncPostInteractionStarAction::afterCommit($commentable, $createCommentDTO->user);

        return $comment;
    }

    public function deleteComment(CreateCommentDTO $createCommentDTO)
    {
        EnsureCanCreateCommentAction::new()->execute($createCommentDTO);

        EnsureCommentExistsAction::new()->execute($createCommentDTO);

        EnsureCanUpdateCommentAction::new()->execute($createCommentDTO);

        $deleted = DeleteCommentAction::new()->execute($createCommentDTO);

        // SCRUM-472: the comment's author's star stays while they still have a like or a comment on the post.
        SyncPostInteractionStarAction::afterCommit($createCommentDTO->comment->commentable, $createCommentDTO->comment->user);

        return $deleted;
    }

    public function getComments(CreateCommentDTO $createCommentDTO)
    {
        // A missing or unsupported commentable was a null dereference (500).
        EnsureCommentableExistsAction::new()->execute($createCommentDTO);

        return CommentResource::collection(
            $createCommentDTO
                ->commentable
                ->comments()
                ->with(['user', 'commentable'])
                ->latest()
                ->paginate(
                    PaginationEnum::preferencesPagination->value
                )
        );
    }
}
