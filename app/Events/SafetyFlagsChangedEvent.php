<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

// SCRUM-32: something changed in the safety alerts -- the administrators' pages refetch their list and
// badge. Only to the administrators who aren't involved in the flag, each on their own channel (security
// review: a ping to everyone, followed by no change, would tell an involved one that something they're
// kept out of happened). No id in it.
class SafetyFlagsChangedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** @param list<int> $adminIds */
    public function __construct(private array $adminIds) {}

    public function broadcastOn(): array
    {
        return array_map(fn (int $id) => new PrivateChannel("users.{$id}"), $this->adminIds);
    }

    public function broadcastWith(): array
    {
        return [];
    }

    public function broadcastAs(): string
    {
        return 'safety.changed';
    }
}
