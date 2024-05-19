<?php

namespace App\Actions\Like;

use App\Actions\Action;
use App\DTOs\CreateLikeDTO;
use App\Exceptions\LikeException;

class EnsureLikeExistsAction extends Action
{
    public function execute(CreateLikeDTO $createLikeDTO)
    {
        if ($createLikeDTO->user->likes()->whereLikeable($createLikeDTO->likeable)->exists()) return;

        throw new LikeException("Like was not found.", 422);
    }
}