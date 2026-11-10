<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

// SCRUM-73: someone left the group -- its open pages refetch it (the member count, the paused
// members a counsellor sees, and so on). No payload: who it was is never broadcast to the group;
// what changed is read from the group, under its own access rules (as TherapyAccessChangedEvent).
class GroupTherapyMembershipChangedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(private int $groupTherapyId) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("groupTherapies.{$this->groupTherapyId}")];
    }

    public function broadcastWith(): array
    {
        return [];
    }

    public function broadcastAs(): string
    {
        return 'group-therapy.membership-changed';
    }
}
