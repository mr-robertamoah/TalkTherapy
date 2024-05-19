<?php

namespace App\Actions\Like;

use App\Actions\Action;
use App\DTOs\CreateLikeDTO;
use App\Exceptions\LikeException;

class EnsureLikeableExistsAction extends Action
{
    public function execute(CreateLikeDTO $createLikeDTO)
    {
        if ($createLikeDTO->likeable) return;

        throw new LikeException("You cannot like without providing what you are liking.", 422);
    }
}