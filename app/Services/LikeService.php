<?php

namespace App\Services;

use App\Actions\Like\CreateLikeAction;
use App\Actions\Like\DeleteLikeAction;
use App\Actions\Like\EnsureCanCreateLikeAction;
use App\Actions\Like\EnsureLikeableExistsAction;
use App\Actions\Like\EnsureLikeExistsAction;
use App\Actions\Post\SyncPostInteractionStarAction;
use App\DTOs\CreateLikeDTO;
use App\Exceptions\LikeException;
use App\Models\Therapy;

class LikeService extends Service
{
    public function like(CreateLikeDTO $createLikeDTO)
    {
        EnsureCanCreateLikeAction::new()->execute($createLikeDTO);

        EnsureLikeableExistsAction::new()->execute($createLikeDTO);

        $like = CreateLikeAction::new()->execute($createLikeDTO);

        // TT-7.11c/SCRUM-472: a like on a counsellor's post may earn them a star. Never blocks the like.
        SyncPostInteractionStarAction::afterCommit($createLikeDTO->likeable, $createLikeDTO->user);

        return $like;
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

        $like = DeleteLikeAction::new()->execute($createLikeDTO);

        // SCRUM-472: the star stays while the user still has a like or a comment on the post.
        SyncPostInteractionStarAction::afterCommit($createLikeDTO->likeable, $createLikeDTO->user);

        return $like;
    }

    public function getLikes(CreateLikeDTO $createLikeDTO)
    {
        // A missing or unsupported likeable was a null dereference (500).
        EnsureLikeableExistsAction::new()->execute($createLikeDTO);

        $likeable = $createLikeDTO->likeable;

        // SCRUM-354/355: never who liked something -- an anonymous client liking their own therapy,
        // or anyone liking a counsellor, would be identified. Only how many, and whether you did.
        return [
            'count' => $likeable->likes()->count(),
            'liked' => (bool) $createLikeDTO->user && $likeable->likes()->where('user_id', $createLikeDTO->user->id)->exists(),
        ];
    }
}
