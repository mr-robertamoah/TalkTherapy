<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

// SCRUM-288: tells a minor's open pages that their therapies were paused (no active guardian left)
// or resumed, so they reload. Convenience only -- every write re-checks on the server. Carries
// nothing but its name, on the minor's own channel (copied from AccountSuspendedEvent).
class GuardianAccessChangedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public const PAUSED = 'guardian-access.paused';

    public const RESUMED = 'guardian-access.resumed';

    public function __construct(private int $userId, private string $name) {}

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
        return $this->name;
    }
}
