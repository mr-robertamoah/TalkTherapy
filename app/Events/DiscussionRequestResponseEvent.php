<?php

namespace App\Events;

use App\Models\Counsellor;
use App\Models\Request;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
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
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        // SCRUM-372: to the discussion's creator as a counsellor. A creator recorded as their user
        // account used to be addressed by their USER id on the counsellors channel -- some other
        // counsellor's channel, with this discussion's name.
        $creator = $this->request->for?->addedby;
        $counsellorId = $creator instanceof User ? $creator->counsellor?->id : $creator?->id;

        return $counsellorId ? [new PrivateChannel("counsellors.{$counsellorId}")] : [];
    }

    public function broadcastAs(): string
    {
        return 'discussion.request.response';
    }

    public function broadcastWith(): array
    {
        return [
            'forName' => $this->request->for->name,
            'counsellorName' => $this->request->to->name,
            'status' => $this->request->status,
            'forType' => $this->request->for->for_type == Therapy::class ? 'Therapy' : 'GroupTherapy',
            'forId' => $this->request->for->for_id,
        ];
    }
}
