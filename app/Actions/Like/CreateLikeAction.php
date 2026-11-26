<?php

namespace App\Actions\Like;

use App\Actions\Action;
use App\DTOs\CreateLikeDTO;
use App\Exceptions\LikeException;
use App\Models\Like;
use Illuminate\Database\UniqueConstraintViolationException;

class CreateLikeAction extends Action
{
    public const ALREADY_LIKED = 'You already like this.';

    public function execute(CreateLikeDTO $createLikeDTO): Like
    {
        // SCRUM-472 (security review M2): one like per person per thing.
        if ($createLikeDTO->user->likes()->whereLikeable($createLikeDTO->likeable)->exists()) {
            throw new LikeException(self::ALREADY_LIKED, 422);
        }

        // One INSERT with the likeable set, so the unique index (SCRUM-478) sees the whole row.
        $like = $createLikeDTO->user->likes()->make();
        $like->likeable()->associate($createLikeDTO->likeable);

        try {
            $like->save();
        } catch (UniqueConstraintViolationException) {
            // SCRUM-478: a concurrent request liked it between the check and the insert -- the same answer as above.
            throw new LikeException(self::ALREADY_LIKED, 422);
        }

        return $like;
    }
}
