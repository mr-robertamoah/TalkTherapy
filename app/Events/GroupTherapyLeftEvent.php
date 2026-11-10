<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

// SCRUM-73: on the leaver's own user channel -- any page they still have open on the group they
// left goes elsewhere and drops the group's channel. Only that group's id, and only to them.
class GroupTherapyLeftEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(private int $userId, private int $groupTherapyId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("users.{$this->userId}")];
    }

    public function broadcastWith(): array
    {
        return ['groupTherapyId' => $this->groupTherapyId];
    }

    public function broadcastAs(): string
    {
        return 'group-therapy.left';
    }
}
