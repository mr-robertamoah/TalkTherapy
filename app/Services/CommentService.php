<?php

namespace App\Services;

use App\Actions\Comment\CreateCommentAction;
use App\Actions\Comment\DeleteCommentAction;
use App\Actions\Comment\EnsureCanCreateCommentAction;
use App\Actions\Comment\EnsureCanUpdateCommentAction;
use App\Actions\Comment\EnsureCommentableExistsAction;
use App\Actions\Comment\EnsureCommentDataIsValidAction;
use App\Actions\Comment\EnsureCommentExistsAction;
use App\DTOs\CreateCommentDTO;
use App\Enums\PaginationEnum;
use App\Http\Resources\CommentResource;

class CommentService extends Service
{
    public function createComment(CreateCommentDTO $createCommentDTO)
    {
        EnsureCanCreateCommentAction::new()->execute($createCommentDTO);

        EnsureCommentableExistsAction::new()->execute($createCommentDTO);

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
        return CommentResource::collection(
            $createCommentDTO
                ->commentable
                ->comments()
                ->latest()
                ->paginate(
                    PaginationEnum::preferencesPagination->value
                )
        );
    }
}