<?php

namespace App\Events;

use App\Models\VideoSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// TT-3.1a/SCRUM-274: the ONE low-frequency "video call started/ended" app-level notification --
// deliberately broadcast ONLY on the existing per-session sessions.{id} PrivateChannel, NOT also
// on the busier therapies.{id}/groupTherapies.{id} PresenceChannel SessionUpdatedEvent uses --
// architect's own explicit decision (documentation/decision-log.md, 2026-09-11): that channel
// already carries chat/roster/topic traffic for the whole therapy, not just this session's two
// participants. The actual WebRTC media signaling (SDP/ICE) never touches Reverb at all -- it's
// handled entirely by whichever provider's own SDK/servers are active.
class VideoSessionStatusChangedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(private VideoSession $videoSession, private string $status)
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
        return 'video-session.status-changed';
    }

    public function broadcastWith(): array
    {
        return [
            'status' => $this->status,
            'sessionId' => $this->videoSession->session_id,
        ];
    }
}
