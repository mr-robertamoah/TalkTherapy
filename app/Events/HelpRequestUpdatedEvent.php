<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

// SCRUM-33: a help request's status changed (connected, ended, expired, cancelled) -- to the
// request's own channel (the person and the counsellor), and to the counsellors whose ring it ends
// ($counsellorIds), so their ring closes. Ids and the status only.
class HelpRequestUpdatedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** @param list<int> $counsellorIds */
    public function __construct(private int $helpRequestId, private string $status, private array $counsellorIds = []) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("help-requests.{$this->helpRequestId}"),
            ...array_map(fn (int $id) => new PrivateChannel("counsellors.{$id}"), $this->counsellorIds),
        ];
    }

    public function broadcastWith(): array
    {
        return ['helpRequestId' => $this->helpRequestId, 'status' => $this->status];
    }

    public function broadcastAs(): string
    {
        return 'help.updated';
    }
}
