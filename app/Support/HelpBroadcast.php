<?php

namespace App\Support;

use App\Events\HelpQueueChangedEvent;

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
    }

    // TT-4.4b: the administrators' queue refetches this request.
    public static function queueChanged(int $helpRequestId): void
    {
        self::send(new HelpQueueChangedEvent($helpRequestId));
    }
}
