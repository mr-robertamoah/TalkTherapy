<?php

namespace App\Events;

use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Models\Session;
use App\Traits\MessageBroadcastTrait;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class MessageSentEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels, MessageBroadcastTrait;

    /**
     * Create a new event instance.
     */
    public function __construct(private Message $message)
    {
        
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $broadcastName = $this->getMessageBroadcastName($this->message);
        // ds($broadcastName);
        $channel = str_contains($broadcastName, 'discussion') ?
            new PresenceChannel($broadcastName) :
            new PrivateChannel($broadcastName);

        return [
            $channel
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.created';
    }

    public function broadcastWith(): array
    {
        $data = $this->getMessageBroadcastData($this->message);
        // ds($data);
        return [
            'message' => $data
        ];
    }
}
