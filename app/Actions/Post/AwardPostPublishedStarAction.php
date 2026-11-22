<?php

namespace App\Actions\Post;

use App\Actions\Action;
use App\Actions\Star\AwardCounsellorStarAction;
use App\Enums\StarSourceEnum;
use App\Models\Counsellor;
use App\Models\Post;
use App\Models\Star;

// TT-7.11b/SCRUM-471: a post published as a counsellor earns that counsellor a star, under the posts' daily cap
// (StarCapPolicy, checked by AwardCounsellorStarAction under the counsellor's row lock). A post made as a user (not a
// counsellor) earns nothing. Key post_published:{post}. Returns the star, or null when none was given.
class AwardPostPublishedStarAction extends Action
{
    public function execute(Post $post): ?Star
    {
        $counsellor = $post->addedby;

        if (! $counsellor instanceof Counsellor) {
            return null;
        }

        return AwardCounsellorStarAction::new()->execute(
            $counsellor, StarSourceEnum::postPublished, $post, "post_published:{$post->id}",
        );
    }
}
