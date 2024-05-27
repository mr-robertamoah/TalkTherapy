<?php

namespace App\Events;

use App\Http\Resources\DiscussionResource;
use App\Models\Discussion;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DiscussionStartedEvent implements ShouldBroadcast
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
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PresenceChannel($this->discussion->getForChannelName()),
        ];
    }

    public function broadcastAs(): string
    {
        return 'discussion.started';
    }

    public function broadcastWith(): array
    {
        return [
            'discussion' => new DiscussionResource($this->discussion)
        ];
    }
}
