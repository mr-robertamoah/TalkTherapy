<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

// SCRUM-33: an "I need help now" ringing on-call counsellors (each on their own counsellor channel).
// Sent now, not queued -- a ring that waits behind other jobs is no use. Ids only: what a counsellor
// sees about the person is fetched (HelpRequestResource's short summary), never broadcast.
class HelpRequestRingingEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** @param list<int> $counsellorIds */
    public function __construct(public readonly int $helpRequestId, public readonly array $counsellorIds) {}

    public function broadcastOn(): array
    {
        return array_map(fn (int $id) => new PrivateChannel("counsellors.{$id}"), $this->counsellorIds);
    }

    public function broadcastWith(): array
    {
        return ['helpRequestId' => $this->helpRequestId];
    }

    public function broadcastAs(): string
    {
        return 'help.ringing';
    }
}
