<?php

namespace App\Events;

use App\Models\VideoSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// TT-3.2f-f/SCRUM-323: a receive-only GroupTherapy member raised their hand -- feeds the
// counsellors' live hand-raise queue (TT-3.2f-j). Same sessions.{id} PrivateChannel and payload
// shape as VideoParticipantRemovedEvent (ids only, never a name -- anonymity-safe by construction).
class HandRaisedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(private VideoSession $videoSession, private int $userId)
    {
        //
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("sessions.{$this->videoSession->session_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'video-session.hand-raised';
    }

    public function broadcastWith(): array
    {
        return [
            'sessionId' => $this->videoSession->session_id,
            'userId' => $this->userId,
        ];
    }
}
