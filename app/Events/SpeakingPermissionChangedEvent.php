<?php

namespace App\Events;

use App\Models\VideoSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// TT-3.2f-f/SCRUM-323: one event for both grant and revoke -- carries the participant's NEW
// capabilities rather than a granted/revoked flag, so the frontend (TT-3.2f-j) can tell an
// audio-only grant (TT-3.2f-g's anonymity rule) from a full one without a second round-trip.
// Purely informational: the provider itself already enforces the capability server-side
// (VideoProviderInterface::updateParticipantCapabilities()), this only updates the UI.
class SpeakingPermissionChangedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        private VideoSession $videoSession,
        private int $userId,
        private bool $canSendAudio,
        private bool $canSendVideo,
    ) {
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
        return 'video-session.speaking-permission-changed';
    }

    public function broadcastWith(): array
    {
        return [
            'sessionId' => $this->videoSession->session_id,
            'userId' => $this->userId,
            'canSendAudio' => $this->canSendAudio,
            'canSendVideo' => $this->canSendVideo,
        ];
    }
}
