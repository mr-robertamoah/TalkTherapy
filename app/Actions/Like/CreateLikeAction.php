<?php

namespace App\Actions\Like;

use App\Actions\Action;
use App\DTOs\CreateLikeDTO;
use App\Exceptions\LikeException;
use App\Models\Like;

class CreateLikeAction extends Action
{
    public const ALREADY_LIKED = 'You already like this.';

    public function execute(CreateLikeDTO $createLikeDTO): Like
    {
        // SCRUM-472 (security review M2): one like per person per thing. Rows from before this check may still hold
        // duplicates, so there is no unique index yet (a follow-up, after deduplicating).
        if ($createLikeDTO->user->likes()->whereLikeable($createLikeDTO->likeable)->exists()) {
            throw new LikeException(self::ALREADY_LIKED, 422);
        }

        $like = $createLikeDTO->user->likes()->create();

        $like->likeable()->associate($createLikeDTO->likeable);
        $like->save();

        return $like;
    }
}
