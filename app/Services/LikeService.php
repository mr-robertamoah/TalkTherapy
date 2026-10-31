<?php

namespace App\Services;

use App\Actions\Like\CreateLikeAction;
use App\Actions\Like\DeleteLikeAction;
use App\Actions\Like\EnsureCanCreateLikeAction;
use App\Actions\Like\EnsureLikeableExistsAction;
use App\Actions\Like\EnsureLikeExistsAction;
use App\DTOs\CreateLikeDTO;
use App\Exceptions\LikeException;
use App\Models\GroupTherapy;
use App\Models\Therapy;

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

        // Not EnsureLikeableExistsAction: removing your own like must still work after a therapy
        // turns private (security review). Only the requester's own row is touched.
        if (! $createLikeDTO->likeable) {
            throw new LikeException(EnsureLikeableExistsAction::NOT_AVAILABLE, 422);
        }

        EnsureLikeExistsAction::new()->execute($createLikeDTO);

        return DeleteLikeAction::new()->execute($createLikeDTO);
    }

    public function getLikes(CreateLikeDTO $createLikeDTO)
    {
        // A missing or unsupported likeable was a null dereference (500).
        EnsureLikeableExistsAction::new()->execute($createLikeDTO);

        $likeable = $createLikeDTO->likeable;

        // SCRUM-354: for a therapy or group, never who liked it -- an anonymous client or member
        // liking their own therapy would be identified. Only how many, and whether you did.
        if ($likeable instanceof Therapy || $likeable instanceof GroupTherapy) {
            return [
                'count' => $likeable->likes()->count(),
                'liked' => (bool) $createLikeDTO->user && $likeable->likes()->where('user_id', $createLikeDTO->user->id)->exists(),
            ];
        }

        return $likeable->likes()->get(['user_id']);
    }
}
