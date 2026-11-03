<?php

namespace App\Services\Daily;

use App\Contracts\VideoProviderInterface;
use App\Enums\ConstantsEnum;
use App\Models\GroupTherapy;
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
            // SCRUM-334: set explicitly rather than inherited from the Daily domain's defaults, so a
            // dashboard change can't quietly widen what a therapy call allows. Each is a documented
            // room property (https://docs.daily.co/reference/rest-api/rooms/create-room).
            //
            // Recording deliberately has NO property here: Daily's `enable_recording` only accepts
            // the recording types to turn ON ("cloud", "cloud-audio-only", "local", "raw-tracks"),
            // has no documented "off" value, isn't a domain-level setting, and recording must be
            // enabled on the room or token before startRecording() works
            // (https://docs.daily.co/docs/daily-js/features/recording). Leaving it unset on both the
            // room and every token (createParticipantCredentials()) IS "off" -- and must stay so
            // until a recording-consent flow exists (SCRUM-29). DailyVideoProviderTest pins that.
            'properties' => [
                'exp' => $this->expiresAt($videoSession)->getTimestamp(),
                // Security review: `exp` alone only stops NEW joins; this also ends the call for anyone
                // still in it when the room expires (Daily room property `eject_at_room_exp`).
                'eject_at_room_exp' => true,
                'enable_chat' => false, // this app's own text chat already covers this
                'max_participants' => $this->maxParticipantsFor($videoSession),
                // The call UI offers no screen sharing, and a shared screen in a therapy call could
                // expose anything on it. Can't be changed once a session starts.
                'enable_screenshare' => false,
                // No lobby: a private room's "knock" lets someone WITHOUT one of our tokens ask a
                // meeting owner (a 1:1 counsellor) to admit them, bypassing every check
                // JoinVideoSessionAction makes. Our tokens are the only way in.
                'enable_knocking' => false,
            ],
        ]);

        return [
            'room_id' => $room['name'],
            'meta' => ['url' => $room['url']],
        ];
    }

    public function createParticipantCredentials(VideoSession $videoSession, User $user, string $displayName, bool $isOwner = false, bool $receiveOnly = false, bool $canSendVideo = true): array
    {
        $properties = [
            'room_name' => $videoSession->provider_room_id,
            // Deliberately $displayName, NOT $user->name -- see VideoProviderInterface's own
            // comment. Only the INITIAL label: Daily lets a participant change their own
            // (setUserName()) and no token/room property prevents it, so our call UI never displays
            // it -- it asks our server by `user_id` instead (SCRUM-334,
            // GetVideoParticipantDisplayNameAction). Still masked, since Daily keeps it in its logs.
            'user_name' => $displayName,
            // SCRUM-357: every client in the room can read this -- never the real user id.
            // SCRUM-334: and none can change it -- Daily sets user_id only from the meeting token
            // (https://docs.daily.co/reference/daily-js/types/daily-participant), which is why the
            // call UI keys its server-resolved names on it.
            'user_id' => $videoSession->participantKeyFor($user->id),
            'is_owner' => $isOwner,
            'exp' => $this->expiresAt($videoSession)->getTimestamp(),
            // Security review: and removes this participant when their token expires, rather than
            // letting an already-joined browser stay on past it (`eject_at_token_exp`).
            'eject_at_token_exp' => true,
            // SCRUM-334: mirrors the room's own enable_screenshare (createRoom()) per participant, so
            // the token's own default (true) can never be what decides it
            // (https://docs.daily.co/reference/rest-api/meeting-tokens/create-meeting-token).
            'enable_screenshare' => false,
        ];

        // TT-3.2f-d/SCRUM-321: server-enforced by Daily itself at the token level -- this
        // participant's own browser cannot publish audio/video even if it tries, regardless of
        // any client-side UI state. Confirmed via the research spike (SCRUM-320): Daily's
        // meeting-token `permissions.canSend` accepts `false` for "none".
        // SCRUM-332: also audio-only ($canSendVideo false) -- same canSendValueFor() shape
        // updateParticipantCapabilities() uses, so the two can't drift. $receiveOnly wins.
        $canSend = $this->canSendValueFor(! $receiveOnly, ! $receiveOnly && $canSendVideo);

        if ($canSend !== true) {
            $properties['permissions'] = ['canSend' => $canSend];
        }

        $token = $this->client->createMeetingToken($properties);

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

    public function removeParticipant(VideoSession $videoSession, User $user): void
    {
        if (! $videoSession->provider_room_id) {
            return;
        }

        $this->client->ejectParticipants($videoSession->provider_room_id, [$videoSession->participantKeyFor($user->id)]);
    }

    public function updateParticipantCapabilities(VideoSession $videoSession, User $user, bool $canSendAudio, bool $canSendVideo): void
    {
        if (! $videoSession->provider_room_id) {
            return;
        }

        $this->client->updateRoomPermissions($videoSession->provider_room_id, [
            $videoSession->participantKeyFor($user->id) => ['canSend' => $this->canSendValueFor($canSendAudio, $canSendVideo)],
        ]);
    }

    // Daily's own `canSend` accepts `true` (all), `false` (none), or an explicit array of the
    // specific media types allowed -- collapses to the simplest form Daily itself documents for
    // each case, rather than always sending an array (e.g. `['audio', 'video']` instead of `true`
    // for the both-allowed case would work identically, but doesn't match the shape Daily's own
    // examples use for "everything").
    private function canSendValueFor(bool $canSendAudio, bool $canSendVideo): array|bool
    {
        if ($canSendAudio && $canSendVideo) {
            return true;
        }

        if (! $canSendAudio && ! $canSendVideo) {
            return false;
        }

        return array_values(array_filter([
            $canSendAudio ? 'audio' : null,
            $canSendVideo ? 'video' : null,
        ]));
    }

    // Rooms/tokens both need an explicit expiry -- Daily strongly recommends never omitting `exp`.
    // Ties to the underlying Session's own end_time (plus a buffer for overrun) when known, else
    // a conservative default so a room is never left open indefinitely.
    private function expiresAt(VideoSession $videoSession): Carbon
    {
        $endTime = $videoSession->session?->end_time;

        return $endTime ? $endTime->clone()->addHours(2) : now()->addHours(6);
    }

    // TT-3.2a/SCRUM-308 (architect finding): a single flat cap would either loosen 1:1 Therapy's
    // own cap or under-cap a GroupTherapy room -- must be computed per session type. Defaults to
    // the smaller Therapy cap if `session`/`for` somehow isn't resolvable (the safer failure mode
    // for a 1:1-shaped room by construction).
    private function maxParticipantsFor(VideoSession $videoSession): int
    {
        return $videoSession->session?->for instanceof GroupTherapy
            ? (int) ConstantsEnum::groupTherapyVideoMaxParticipants->value
            : (int) ConstantsEnum::therapyVideoMaxParticipants->value;
    }
}
