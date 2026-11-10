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
        $counsellor = $user->counsellor;
        if (! $counsellor) {
            return collect();
        }

        // On call: anything waiting they'd be rung for; otherwise only what an administrator rang them for.
        return HelpRequest::query()->whereStatus(HelpRequestStatusEnum::waiting)
            ->when(! $counsellor->isAvailableForUrgentHelp(), fn ($query) => $query->where('pulled_counsellor_id', $counsellor->id))
            ->oldest()->limit(20)->get()
            ->filter(fn (HelpRequest $request) => RingAvailableCounsellorsAction::mayAnswer($request, $counsellor))
            ->values();
    }
}
