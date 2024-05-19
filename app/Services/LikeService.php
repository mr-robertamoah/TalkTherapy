<?php

namespace App\Services;

use App\Actions\Like\CreateLikeAction;
use App\Actions\Like\DeleteLikeAction;
use App\Actions\Like\EnsureCanCreateLikeAction;
use App\Actions\Like\EnsureLikeableExistsAction;
use App\Actions\Like\EnsureLikeExistsAction;
use App\DTOs\CreateLikeDTO;

class LikeService extends Service
{
    public function like(CreateLikeDTO $createLikeDTO)
    {
        EnsureCanCreateLikeAction::new()->execute($createLikeDTO);

        EnsureLikeableExistsAction::new()->execute($createLikeDTO);

        return CreateLikeAction::new()->execute($createLikeDTO);
    }
    
    public function dislike(CreateLikeDTO $createLikeDTO)
    {
        EnsureCanCreateLikeAction::new()->execute($createLikeDTO);

        EnsureLikeableExistsAction::new()->execute($createLikeDTO);

        EnsureLikeExistsAction::new()->execute($createLikeDTO);

        return DeleteLikeAction::new()->execute($createLikeDTO);
    }

    public function getLikes(CreateLikeDTO $createLikeDTO)
    {
        return $createLikeDTO->likeable->likes()->get(['user_id']);
    }
}