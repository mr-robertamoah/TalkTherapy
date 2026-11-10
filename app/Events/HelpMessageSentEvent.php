<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

// SCRUM-33: a message in a help chat -- ids only; the other side fetches it (TT-4.21's rule).
class HelpMessageSentEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(private int $helpRequestId, private int $messageId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("help-requests.{$this->helpRequestId}")];
    }

    public function broadcastWith(): array
    {
        return ['helpRequestId' => $this->helpRequestId, 'messageId' => $this->messageId];
    }

    public function broadcastAs(): string
    {
        return 'help.message';
    }
}
