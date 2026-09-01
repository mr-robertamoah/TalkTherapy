<?php

namespace App\Events;

use App\Http\Resources\DiscussionResource;
use App\Models\Discussion;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DiscussionUpdatedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(private Discussion $discussion)
    {
        //
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    // SCRUM-59/TT-1.10: the PrivateChannel("discussions.{id}") broadcast this event used to also
    // send was dead code -- the only frontend listener for .discussion.updated
    // (useTherapyState.js) is chained off Echo.join(), i.e. the PresenceChannel below, never a
    // private-channel subscription to this discussion. Removed rather than kept "just in case",
    // unlike SessionUpdatedEvent's own PrivateChannel broadcast, which SessionBadge.vue genuinely
    // consumes standalone (SCRUM-15).
    public function broadcastOn(): array
    {
        return [
            new PresenceChannel($this->discussion->getForChannelName()),
        ];
    }

    public function broadcastAs(): string
    {
        return 'discussion.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'discussion' => new DiscussionResource($this->discussion),
        ];
    }
}
