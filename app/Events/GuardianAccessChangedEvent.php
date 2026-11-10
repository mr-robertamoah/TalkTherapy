<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

// SCRUM-288: tells open pages that a minor's therapies were paused (no active guardian left) or
// resumed, so they reload -- on the minor's own user channel and (SCRUM-381) on the user channel of
// each of their group counsellors (a 1:1 counsellor gets TherapyAccessChangedEvent instead;
// AnnounceGuardianAccessChangeAction). Convenience only --
// every write re-checks on the server. Carries nothing but its name (copied from
// AccountSuspendedEvent).
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
