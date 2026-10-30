<?php

namespace App\Events;

use App\Models\VideoSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// TT-3.2f-f/SCRUM-323: a member's open hand-raise was closed -- either they lowered it themselves,
// or a counsellor granted them speaking permission (which acts on the raise). Same sessions.{id}
// PrivateChannel and payload shape as HandRaisedEvent.
class HandLoweredEvent implements ShouldBroadcast
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
        return 'video-session.hand-lowered';
    }

    public function broadcastWith(): array
    {
        return [
            'sessionId' => $this->videoSession->session_id,
            'userId' => $this->userId,
        ];
    }
}
