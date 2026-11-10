<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

// SCRUM-386: someone asked to join a group -- on its creator's own user channel, so their open
// group page (and Requests list) refetch it. Ids only: who asked is read back through the
// request's own masking rules (RequestResource), never broadcast.
class GroupTherapyMembershipRequestReceivedEvent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(private int $creatorUserId, private int $groupTherapyId, private int $requestId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("users.{$this->creatorUserId}")];
    }

    public function broadcastWith(): array
    {
        return ['groupTherapyId' => $this->groupTherapyId, 'requestId' => $this->requestId];
    }

    public function broadcastAs(): string
    {
        return 'group-therapy.membership-requested';
    }
}
