<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

// SCRUM-39: someone's notifications changed (a new one, or some marked read) -- their open pages refetch
// the bell's count. On their own users.{id} channel only (their counsellor profile's notifications too),
// and with nothing in it: never a notification's content.
class NotificationsChangedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(private int $userId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("users.{$this->userId}")];
    }

    public function broadcastWith(): array
    {
        return [];
    }

    public function broadcastAs(): string
    {
        return 'notifications.changed';
    }
}
