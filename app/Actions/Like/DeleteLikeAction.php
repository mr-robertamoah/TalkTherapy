<?php

namespace App\Actions\Like;

use App\Actions\Action;
use App\DTOs\CreateLikeDTO;

class DeleteLikeAction extends Action
{
    public function execute(CreateLikeDTO $createLikeDTO)
    {
        $like = $createLikeDTO->user->likes()->whereLikeable($createLikeDTO->likeable)->first();
        $like->delete();

        return $like;
    }
}