<?php

namespace App\Services\Daily;

use App\Contracts\VideoProviderInterface;
use App\Models\User;
use App\Models\VideoSession;
use Illuminate\Support\Carbon;

class DailyVideoProvider implements VideoProviderInterface
{
    public function __construct(private DailyClient $client) {}

    public function createRoom(VideoSession $videoSession): array
    {
        // Deterministic, traceable name (not Daily's own auto-generated random name) -- lets
        // anyone debugging a specific VideoSession find its Daily room directly. $videoSession
        // must already be persisted (has an id) before this is called -- see
        // JoinVideoSessionAction's own ordering.
        $name = "session-{$videoSession->session_id}-{$videoSession->id}";

        $room = $this->client->createRoom([
            'name' => $name,
            'privacy' => 'private',
            'properties' => [
                'exp' => $this->expiresAt($videoSession)->getTimestamp(),
                'enable_chat' => false, // this app's own text chat already covers this
                'max_participants' => 2,
            ],
        ]);

        return [
            'room_id' => $room['name'],
            'meta' => ['url' => $room['url']],
        ];
    }

    public function createParticipantCredentials(VideoSession $videoSession, User $user, string $displayName, bool $isOwner = false): array
    {
        $token = $this->client->createMeetingToken([
            'room_name' => $videoSession->provider_room_id,
            // Deliberately $displayName, NOT $user->name -- see VideoProviderInterface's own
            // comment. This is the label every OTHER participant in the room sees.
            'user_name' => $displayName,
            'user_id' => (string) $user->id,
            'is_owner' => $isOwner,
            'exp' => $this->expiresAt($videoSession)->getTimestamp(),
        ]);

        return [
            'url' => data_get($videoSession->provider_meta, 'url'),
            'token' => $token['token'],
        ];
    }

    public function endRoom(VideoSession $videoSession): void
    {
        if (! $videoSession->provider_room_id) {
            return;
        }

        $this->client->deleteRoom($videoSession->provider_room_id);
    }

    // Rooms/tokens both need an explicit expiry -- Daily strongly recommends never omitting `exp`.
    // Ties to the underlying Session's own end_time (plus a buffer for overrun) when known, else
    // a conservative default so a room is never left open indefinitely.
    private function expiresAt(VideoSession $videoSession): Carbon
    {
        $endTime = $videoSession->session?->end_time;

        return $endTime ? $endTime->clone()->addHours(2) : now()->addHours(6);
    }
}
