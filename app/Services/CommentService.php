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

        return CreateCommentAction::new()->execute($createCommentDTO);
    }

    public function deleteComment(CreateCommentDTO $createCommentDTO)
    {
        EnsureCanCreateCommentAction::new()->execute($createCommentDTO);

        EnsureCommentExistsAction::new()->execute($createCommentDTO);

        EnsureCanUpdateCommentAction::new()->execute($createCommentDTO);

        return DeleteCommentAction::new()->execute($createCommentDTO);
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
