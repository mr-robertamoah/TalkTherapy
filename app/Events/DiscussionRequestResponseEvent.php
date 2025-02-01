<?php

namespace App\Events;

use App\Models\Counsellor;
use App\Models\Request;
use App\Models\Therapy;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DiscussionRequestResponseEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(private Request $request)
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
            new PresenceChannel("counsellors.{$this->request->for->addedby->id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'request.response';
    }

    public function broadcastWith(): array
    {
        return [
            'name' => $this->request->to->name,
            'status' => $this->request->status,
            'forType' => $this->request->forType == Therapy::class ? 'Therapy' : 'GroupTherapy',
            'forId' => $this->request->forId,
        ];
    }
}
