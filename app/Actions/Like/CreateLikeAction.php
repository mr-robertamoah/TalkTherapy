<?php

namespace App\Actions\Like;

use App\Actions\Action;
use App\DTOs\CreateLikeDTO;
use App\Exceptions\LikeException;
use App\Models\Like;

class CreateLikeAction extends Action
{
    public function execute(CreateLikeDTO $createLikeDTO) : Like
    {
        $like = $createLikeDTO->user->likes()->create();

        $like->likeable()->associate($createLikeDTO->likeable);
        $like->save();
        
        return $like;
    }
}