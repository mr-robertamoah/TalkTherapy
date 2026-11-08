<?php

namespace App\Actions\Help;

use App\Actions\Action;
use App\Enums\HelpRequestStatusEnum;
use App\Models\HelpRequest;
use App\Models\User;
use Illuminate\Support\Collection;

// SCRUM-33 (review): the requests waiting right now that $user, as an on-call counsellor, would be rung
// for (RingAvailableCounsellorsAction::ringableFor) -- oldest first. Someone who switches on (or reloads
// their page) while a person waits is shown them, rather than the person waiting for a new ring.
class WaitingRequestsForCounsellorAction extends Action
{
    /** @return Collection<int, HelpRequest> */
    public function execute(User $user): Collection
    {
        $counsellorId = (int) $user->counsellor?->id;
        if (! $counsellorId || ! $user->counsellor->isAvailableForUrgentHelp()) {
            return collect();
        }

        return HelpRequest::query()->whereStatus(HelpRequestStatusEnum::waiting)->oldest()->limit(20)->get()
            ->filter(fn (HelpRequest $request) => in_array($counsellorId, RingAvailableCounsellorsAction::ringableFor($request), true))
            ->values();
    }
}
