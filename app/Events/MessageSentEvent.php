<?php

namespace App\Events;

use App\Models\Message;
use App\Traits\MessageBroadcastTrait;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSentEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, MessageBroadcastTrait, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(private Message $message) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $broadcastName = $this->getMessageBroadcastName($this->message);
        $channel = str_contains($broadcastName, 'discussion') ?
            new PresenceChannel($broadcastName) :
            new PrivateChannel($broadcastName);

        return [
            $channel,
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.created';
    }

    public function broadcastWith(): array
    {
        $data = $this->getMessageBroadcastData($this->message);

        return [
            'message' => $data,
        ];
    }
}
