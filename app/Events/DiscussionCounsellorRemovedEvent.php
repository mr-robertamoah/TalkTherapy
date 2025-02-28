<?php

namespace App\Events;

use App\Models\Counsellor;
use App\Models\Discussion;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DiscussionCounsellorRemovedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(private Discussion $discussion, private Counsellor $counsellor)
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
            new PresenceChannel("discussions.{$this->discussion->id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'discussion.removecounsellor';
    }

    public function broadcastWith(): array
    {
        return [
            'counsellorId' => $this->counsellor->id
        ];
    }
}
