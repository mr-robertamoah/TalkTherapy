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
    public function createParticipantCredentials(VideoSession $videoSession, User $user, string $displayName, bool $isOwner = false, bool $receiveOnly = false): array
    {
        $args = [
            'MeetingId' => $videoSession->provider_room_id,
            'ExternalUserId' => (string) $user->id,
        ];

        // TT-3.2f-d/SCRUM-321: server-enforced by Chime itself, not merely omitted client-side --
        // confirmed via the research spike (SCRUM-320). Omitted entirely (not just set to
        // SendReceive) for a full participant, unchanged from before this ticket.
        if ($receiveOnly) {
            $args['Capabilities'] = ['Audio' => 'Receive', 'Video' => 'Receive', 'Content' => 'Receive'];
        }

        $response = $this->client->createAttendee($args);

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

    // TT-3.2b/SCRUM-309: no stored Chime-specific participant id to key off (see ChimeClient's
    // own comment) -- looks the live attendee up by its ExternalUserId (== $user->id, set at
    // CreateAttendee time) via ListAttendees, then deletes that specific AttendeeId. A no-op if
    // the user isn't currently a live attendee at all (already left, or never actually joined
    // this provider-side room) -- matches endRoom()'s own "nothing to do" precedent rather than
    // throwing for an already-moot removal.
    public function removeParticipant(VideoSession $videoSession, User $user): void
    {
        if (! $videoSession->provider_room_id) {
            return;
        }

        $attendees = $this->client->listAttendees($videoSession->provider_room_id)['Attendees'] ?? [];

        $attendee = collect($attendees)->firstWhere('ExternalUserId', (string) $user->id);

        if (! $attendee) {
            return;
        }

        $this->client->deleteAttendee($videoSession->provider_room_id, $attendee['AttendeeId']);
    }

    // TT-3.2f-e/SCRUM-322: same live-attendee lookup pattern as removeParticipant() above -- no
    // stored Chime-specific participant id to key off, so the AttendeeId is looked up by matching
    // ExternalUserId (== $user->id) via ListAttendees. A no-op if the user isn't currently a live
    // attendee, same "nothing to do" precedent as removeParticipant().
    public function updateParticipantCapabilities(VideoSession $videoSession, User $user, bool $canSendAudio, bool $canSendVideo): void
    {
        if (! $videoSession->provider_room_id) {
            return;
        }

        $attendees = $this->client->listAttendees($videoSession->provider_room_id)['Attendees'] ?? [];

        $attendee = collect($attendees)->firstWhere('ExternalUserId', (string) $user->id);

        if (! $attendee) {
            return;
        }

        // Content is always 'Receive' here regardless of $canSendVideo -- this app has no
        // screen-share/content-sharing feature to ever need 'Send' for, and AWS itself requires
        // Video to be Receive/SendReceive (never None) whenever Content is Receive/SendReceive --
        // Video is always one of those two here, so this is always valid. This is an invariant of
        // THIS APP's own usage (neither this method nor createParticipantCredentials() ever sets
        // Video to 'None'), not a general AWS guarantee -- revisit this hardcode if a future
        // change ever introduces a Video: 'None' capability state.
        $this->client->updateAttendeeCapabilities($videoSession->provider_room_id, $attendee['AttendeeId'], [
            'Audio' => $canSendAudio ? 'SendReceive' : 'Receive',
            'Video' => $canSendVideo ? 'SendReceive' : 'Receive',
            'Content' => 'Receive',
        ]);
    }
}
