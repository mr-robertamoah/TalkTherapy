<?php

namespace App\Support;

use App\Events\HelpQueueChangedEvent;
use App\Events\HelpRequestRingingEvent;
use App\Events\HelpRequestUpdatedEvent;

// SCRUM-33 (devops review): a help chat's live updates are best effort. A broadcast is sent once the
// database work is committed, and a broadcasting failure (Reverb down) is reported, never thrown --
// otherwise a person in crisis would see an error for a request that was in fact saved, and the
// every-minute sweep would stop at its first failure. Rings still go out straight away (the events
// broadcast now, not queued); a waiting person also re-checks on their own.
final class HelpBroadcast
{
    public static function send(object $event): void
    {
        BestEffortBroadcast::send($event);

        // SCRUM-399: a ring, or its end, also goes to on-call counsellors' devices -- scheduled on its own, so
        // it still goes out when the broadcast fails (Reverb down), which is when it matters most.
        match (true) {
            $event instanceof HelpRequestRingingEvent => HelpPush::ring($event->helpRequestId, $event->counsellorIds),
            $event instanceof HelpRequestUpdatedEvent => HelpPush::withdraw($event->helpRequestId, $event->counsellorIds),
            default => null,
        };
    }

    // TT-4.4b: the administrators' queue refetches this request.
    public static function queueChanged(int $helpRequestId): void
    {
        self::send(new HelpQueueChangedEvent($helpRequestId));
    }
}
