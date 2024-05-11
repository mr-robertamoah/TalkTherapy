<?php

namespace App\Actions\Discussion;

use App\Actions\Action;
use App\DTOs\CreateDiscussionDTO;
use App\Models\Discussion;
use Carbon\Carbon;

class CreateDiscussionAction extends Action
{
    public function execute(CreateDiscussionDTO $createDiscussionDTO) : Discussion
    {
        $discussion = $createDiscussionDTO->addedby->addedDiscussions()->create([
            'name' => $createDiscussionDTO->name,
            'description' => $createDiscussionDTO->description,
            'start_ime' => (new Carbon($createDiscussionDTO->startTime))->utc(),
            'end_time' => (new Carbon($createDiscussionDTO->endTime))->utc(),
            'session_id' => $createDiscussionDTO->session?->id,
        ]);

        $discussion->for()->associate($createDiscussionDTO->for);
        $discussion->save();

        return $discussion;
    }
}