<?php

namespace App\Actions\Like;

use App\Actions\Action;
use App\DTOs\CreateLikeDTO;
use App\Exceptions\LikeException;

class EnsureCanCreateLikeAction extends Action
{
    public function execute(CreateLikeDTO $createLikeDTO)
    {
        if ($createLikeDTO->user) return;

        throw new LikeException("You cannot like when not a user. Please try logging in first.", 422);
    }
}