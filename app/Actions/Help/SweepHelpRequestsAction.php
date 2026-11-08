<?php

namespace App\Actions\Help;

use App\Actions\Action;
use App\Enums\HelpRequestStatusEnum;
use App\Events\HelpRequestUpdatedEvent;
use App\Models\HelpRequest;
use App\Support\HelpBroadcast;

// SCRUM-33 (scheduled every minute): a request nobody answered within config help.expire_after_minutes
// expires; a chat past its session's end time ends. Either way the person can ask again. Each is its
// own conditional update, so an accept or an end at the same moment wins cleanly.
class SweepHelpRequestsAction extends Action
{
    public function execute(): array
    {
        // TT-4.4b: alerts first, for anything the delayed job hasn't sent.
        $alerted = EscalateHelpRequestsAction::new()->execute();

        $expired = 0;
        HelpRequest::query()->whereStatus(HelpRequestStatusEnum::waiting)
            ->where('created_at', '<=', now()->subMinutes((int) config('help.expire_after_minutes')))
            // By id, not by offset: the status being changed is what's filtered on.
            ->lazyById()
            ->each(function (HelpRequest $request) use (&$expired) {
                $ringing = RingAvailableCounsellorsAction::ringingFor($request);
                if (CloseHelpRequestAction::new()->execute($request, HelpRequestStatusEnum::waiting, HelpRequestStatusEnum::expired, 'time')) {
                    $expired++;
                    HelpBroadcast::send(new HelpRequestUpdatedEvent($request->id, HelpRequestStatusEnum::expired->value, $ringing));
                }
            });

        $ended = 0;
        HelpRequest::query()->whereStatus(HelpRequestStatusEnum::connected)
            ->where(fn ($query) => $query
                ->whereHas('session', fn ($query) => $query->where('end_time', '<=', now()))
                // TT-4.4c: and one whose session row is missing (removed since, or never written), once its time
                // is up from when it was answered -- else it would hold the person's one live request for ever.
                ->orWhere(fn ($query) => $query->whereDoesntHave('session')
                    ->where('accepted_at', '<=', now()->subMinutes((int) config('help.session_minutes')))))
            ->lazyById()
            ->each(function (HelpRequest $request) use (&$ended) {
                if (CloseHelpRequestAction::new()->execute($request, HelpRequestStatusEnum::connected, HelpRequestStatusEnum::ended, 'time')) {
                    $ended++;
                    HelpBroadcast::send(new HelpRequestUpdatedEvent($request->id, HelpRequestStatusEnum::ended->value));
                }
            });

        return ['alerted' => $alerted, 'expired' => $expired, 'ended' => $ended];
    }
}
