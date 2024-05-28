<?php

namespace App\Actions\Discussion;

use App\Actions\Action;
use App\DTOs\GetDiscussionsDTO;
use App\Events\DiscussionCounsellorRemovedEvent;
use App\Notifications\DiscussionCounsellorRemovedNotification;

class RemoveCounsellorFromDiscussionAction extends Action
{
    public function execute(GetDiscussionsDTO $getDiscussionsDTO)
    {
        $getDiscussionsDTO->discussion->counsellors()->detach($getDiscussionsDTO->counsellor->id);

        broadcast(
            new DiscussionCounsellorRemovedEvent(
                $getDiscussionsDTO->discussion,
                $getDiscussionsDTO->counsellor
            )
        )->toOthers();

        $getDiscussionsDTO->counsellor->notify(
            new DiscussionCounsellorRemovedNotification($getDiscussionsDTO->discussion)
        );
    }
}