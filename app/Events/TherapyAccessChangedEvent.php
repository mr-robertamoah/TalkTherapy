<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

// SCRUM-288: an individual therapy was paused or resumed for its minor client's guardian -- its
// open pages (the counsellor's) refetch it. No payload: what changed is read from the therapy,
// under its own access rules. On the presence channel every therapy page joins (as
// SessionUpdatedEvent). Never sent on a group's channel, where it would tell the other members
// something about one member.
class TherapyAccessChangedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(private int $therapyId) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("therapies.{$this->therapyId}")];
    }

    public function broadcastWith(): array
    {
        return [];
    }

    public function broadcastAs(): string
    {
        return 'therapy.access-changed';
    }
}
