<?php

namespace App\Events;

use App\Models\VideoSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// TT-3.2b/SCRUM-309 (architect note): a distinct, dedicated event, not an overload of
// VideoSessionStatusChangedEvent -- that one only ever carries a plain status string, never a
// specific participant. Shares the same sessions.{id} PrivateChannel as that event and the
// pre-existing chat/status-change traffic (see VideoSessionStatusChangedEvent's own comment for
// why this app deliberately keeps video signaling off the busier per-therapy channel).
//
// The removed participant's own frontend client (TT-3.2c) listens for this and force-disconnects
// -- distinct from a transient network disconnect (TT-3.1d's own scope), which must never look
// the same to the removed user as being deliberately ejected.
class VideoParticipantRemovedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(private VideoSession $videoSession, private int $removedUserId)
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
        return 'video-session.participant-removed';
    }

    public function broadcastWith(): array
    {
        return [
            'sessionId' => $this->videoSession->session_id,
            'removedUserId' => $this->removedUserId,
        ];
    }
}
