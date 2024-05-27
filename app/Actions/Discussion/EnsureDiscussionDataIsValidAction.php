<?php

namespace App\Actions\Discussion;

use App\Actions\Action;
use App\DTOs\CreateDiscussionDTO;
use App\Enums\TherapyStatusEnum;
use App\Exceptions\DiscussionException;
use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\Session;
use Carbon\Carbon;

class EnsureDiscussionDataIsValidAction extends Action
{
    public function execute(CreateDiscussionDTO $createDiscussionDTO)
    {
        if (is_null($createDiscussionDTO->for)) 
            throw new DiscussionException("No therapy or group therapy for the discussion was given.", 422);
        
        $therapy = $createDiscussionDTO->for;

        if ($therapy->status == TherapyStatusEnum::ended->value) 
            throw new DiscussionException("You cannot a create discussion for a therapy which has ended.", 422);

        $startTime = new Carbon($createDiscussionDTO->startTime);
        $endTime = new Carbon($createDiscussionDTO->endTime);
        if (
            $startTime->addMinutes(30)->greaterThan(new Carbon($createDiscussionDTO->endTime))
        ) 
            throw new DiscussionException("The end time must be at least 30 minutes from the start time.", 422);
            
        if (
            Discussion::query()
                ->when($createDiscussionDTO->discussion, function ($query) use ($createDiscussionDTO) {
                    $query->whereNot('id', $createDiscussionDTO->discussion->id);
                })
                ->where(function ($query) use ($createDiscussionDTO) {
                    $query
                        ->wherePending()
                        ->whereAddedby($createDiscussionDTO->addedby);
                })
                ->orWhere(function ($query) use ($createDiscussionDTO) {
                    $query
                        ->wherePending()
                        ->whereCounsellor($createDiscussionDTO->addedby);
                })
                ->whereDateIsBetweenStartAndEndTimes($startTime)
                ->exists()
        ) 
            throw new DiscussionException("The start time of a discussion cannot fall within the start and end time of other discussions.", 422);

        if (
            Discussion::query()
                ->when($createDiscussionDTO->discussion, function ($query) use ($createDiscussionDTO) {
                    $query->whereNot('id', $createDiscussionDTO->discussion->id);
                })
                ->where(function ($query) use ($createDiscussionDTO) {
                    $query
                        ->wherePending()
                        ->whereAddedby($createDiscussionDTO->addedby);
                })
                ->orWhere(function ($query) use ($createDiscussionDTO) {
                    $query
                        ->wherePending()
                        ->whereCounsellor($createDiscussionDTO->addedby);
                })
                ->whereIsThirtyMinituesBeforeOrAfter($startTime, $endTime)
                ->exists()
        ) 
            throw new DiscussionException("The discussion must start at least 30 minutes before or after other discussions of this therapy.", 422);
            
        if (
            $therapy
                ->sessions()
                ->whereDateIsBetweenStartAndEndTimes($startTime)
                ->exists()
        ) 
            throw new DiscussionException("The start time of a discussion cannot fall within the start and end time of other sessions.", 422);

        if (
            $therapy
                ->sessions()
                ->whereIsThirtyMinituesBeforeOrAfter($startTime, $endTime)
                ->exists()
        ) 
            throw new DiscussionException("The discussion must start at least 30 minutes before or after other sessions of this therapy.", 422);
        
        if ($therapy::class == GroupTherapy::class) return; // TODO add some for group therapy

        if (
            Session::query()
                ->wherePending()
                ->whereHas('for', function ($query) use ($therapy) {
                    $query->whereParticipant($therapy->counsellor->user);
                })
                ->whereIsThirtyMinituesBeforeOrAfter($startTime, $endTime)
                ->exists()
        ) 
            throw new DiscussionException("Counsellor has therapy sessions less than 30 minutes before or after the time for this discussion.", 422);
    }
}