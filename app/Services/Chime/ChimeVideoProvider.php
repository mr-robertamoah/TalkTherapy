<?php

namespace App\Services\Chime;

use App\Contracts\VideoProviderInterface;
use App\Models\User;
use App\Models\VideoSession;
use Illuminate\Support\Str;

// Chime SDK Meetings has no attendee-level "owner"/"host" concept (unlike Daily's is_owner), so
// $isOwner is accepted for interface parity but has no effect here.
class ChimeVideoProvider implements VideoProviderInterface
{
    public function __construct(private ChimeClient $client) {}

    public function createRoom(VideoSession $videoSession): array
    {
        $response = $this->client->createMeeting([
            // An idempotency token, not a persisted identifier -- a fresh one per createRoom()
            // call is correct, since each call is a genuinely new meeting-creation request.
            'ClientRequestToken' => (string) Str::uuid(),
            // Deterministic, traceable id (not an AWS-generated one) -- lets anyone debugging a
            // specific VideoSession find its Chime meeting directly. $videoSession must already
            // be persisted (has an id) before this is called -- see JoinVideoSessionAction's own
            // ordering.
            'ExternalMeetingId' => "session-{$videoSession->session_id}-{$videoSession->id}",
            'MediaRegion' => config('services.chime.media_region'),
        ]);

        $meeting = $response['Meeting'];

        return [
            'room_id' => $meeting['MeetingId'],
            // The full Meeting object (MediaPlacement URLs etc.) is required again when minting
            // every participant's own credentials below, and the frontend Chime SDK client needs
            // it verbatim -- kept here rather than re-fetched, since Chime's API has no
            // "describe meeting" call cheaper than this.
            'meta' => ['meeting' => $meeting],
        ];
    }

    // $displayName is unused here -- Chime SDK Meetings' CreateAttendee API has no display-name
    // field at all (only ExternalUserId); the frontend maps attendee id -> display name itself.
    // Still part of the interface's own signature for parity with DailyVideoProvider, which does
    // send $displayName to its provider (see VideoProviderInterface's own comment on why).
    public function createParticipantCredentials(VideoSession $videoSession, User $user, string $displayName, bool $isOwner = false): array
    {
        $response = $this->client->createAttendee([
            'MeetingId' => $videoSession->provider_room_id,
            'ExternalUserId' => (string) $user->id,
        ]);

        return [
            'meeting' => data_get($videoSession->provider_meta, 'meeting'),
            'attendee' => $response['Attendee'],
        ];
    }

    public function endRoom(VideoSession $videoSession): void
    {
        if (! $videoSession->provider_room_id) {
            return;
        }

        $this->client->deleteMeeting($videoSession->provider_room_id);
    }
}
