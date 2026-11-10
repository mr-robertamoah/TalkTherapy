<?php

namespace App\Events;

use App\Http\Resources\DiscussionResource;
use App\Models\Discussion;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
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
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        // SCRUM-367: counsellors-only -- never the therapy's channel, which its client and group
        // members also join.
        return array_map(
            fn (int $counsellorId) => new PrivateChannel("counsellors.{$counsellorId}"),
            $this->discussion->participantCounsellorIds()
        );
    }

    public function broadcastAs(): string
    {
        return 'discussion.started';
    }

    public function broadcastWith(): array
    {
        return [
            'discussion' => new DiscussionResource($this->discussion),
        ];
    }
}
