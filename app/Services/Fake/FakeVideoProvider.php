<?php

namespace App\Services\Fake;

use App\Contracts\VideoProviderInterface;
use App\Models\User;
use App\Models\VideoSession;

// SCRUM-458 (TT-3.3a): a provider adapter with no provider behind it, for local development and
// Playwright browser QA without Daily/Chime keys. Bound only in `local`/`testing` -- the container
// binding refuses it anywhere else (App\Providers\VideoServiceProvider). Only the adapter is
// swapped: every join check (payment, availability, guardian consent, capabilities) still runs in
// JoinVideoSessionAction exactly as it does for a real provider.
//
// Makes no network call and keeps no state: there is no room to tear down and no live participant
// to eject or re-permission, so endRoom()/removeParticipant()/updateParticipantCapabilities() are
// no-ops. Their visible effects in the browser come from what already drives them for a real
// provider: the session's broadcasts (ended, participant-removed, speaking-permission-changed),
// which resources/js/Video/FakeVideoClient.js listens to the way a provider SDK would hear its own
// server.
class FakeVideoProvider implements VideoProviderInterface
{
    public function createRoom(VideoSession $videoSession): array
    {
        return [
            'room_id' => "fake-session-{$videoSession->session_id}-{$videoSession->id}",
            'meta' => [],
        ];
    }

    // What the fake browser client needs to stand in for a provider token. `canSend` is the
    // provider-enforced capability (the analogue of Daily's token `permissions.canSend` / Chime's
    // attendee Capabilities) -- the client refuses to publish anything it doesn't allow, which is
    // separate from the display-only `participant` state JoinVideoSessionAction adds alongside.
    // $displayName is deliberately unused: nothing here is shown to anyone (the call UI resolves
    // every label from our server by participant key, SCRUM-334).
    public function createParticipantCredentials(VideoSession $videoSession, User $user, string $displayName, bool $receiveOnly = false, bool $canSendVideo = true): array
    {
        return [
            'room' => $videoSession->provider_room_id,
            'sessionId' => $videoSession->session_id,
            // SCRUM-357: the per-session participant key, never the user id.
            'participantKey' => $videoSession->participantKeyFor($user->id),
            // $receiveOnly wins, as the interface requires.
            'canSend' => [
                'audio' => ! $receiveOnly,
                'video' => ! $receiveOnly && $canSendVideo,
            ],
        ];
    }

    public function endRoom(VideoSession $videoSession): void {}

    public function removeParticipant(VideoSession $videoSession, User $user): void {}

    public function updateParticipantCapabilities(VideoSession $videoSession, User $user, bool $canSendAudio, bool $canSendVideo): void {}
}
