<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

// SCRUM-33 (TT-4.4b): a help request changed (asked, answered, ended, alerted, rung, closed) -- to the
// administrators' urgent help queue, which refetches. Its id only.
class HelpQueueChangedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(private int $helpRequestId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin.help-requests')];
    }

    public function broadcastWith(): array
    {
        return ['helpRequestId' => $this->helpRequestId];
    }

    public function broadcastAs(): string
    {
        return 'help.queue';
    }
}
