<?php

namespace App\Contracts;

use App\Models\User;
use App\Models\VideoSession;

// TT-3.1a/SCRUM-274: the one abstraction every caller in this app depends on -- never a concrete
// DailyVideoProvider/ChimeVideoProvider directly (resolve this interface from the container,
// bound in App\Providers\VideoServiceProvider per config('video.provider')). Deliberately thin:
// each provider's own actual join-credential SHAPE differs completely (Daily: a room URL + a
// bearer token; Chime: a full Meeting response object + an Attendee response object), so this
// contract does not attempt to normalize that shape -- createParticipantCredentials() returns
// whatever provider-specific, JSON-serializable payload the frontend passes straight through to
// whichever SDK is actually active. What IS normalized is the lifecycle contract every provider
// must satisfy: create a room, mint one participant's credentials against it, end it.
interface VideoProviderInterface
{
    // Creates a room with the provider for this VideoSession. Returns the raw, provider-specific
    // data the caller should persist on VideoSession itself (provider_room_id / provider_meta) --
    // this method does not persist anything itself, callers own that (keeps this class a pure
    // API wrapper, testable without touching the database).
    //
    // Returns ['room_id' => string, 'meta' => array] -- `meta` holds whatever raw provider
    // response fields are needed later to mint participant credentials (e.g. Chime's Meeting
    // object; empty for Daily, which only needs the room name/URL already captured in room_id).
    public function createRoom(VideoSession $videoSession): array;

    // Mints this specific user's own join credentials for an already-created room. $displayName
    // is the name OTHER participants in the room will see labeled next to this user -- resolved
    // by the caller (JoinVideoSessionAction), never derived from $user->name here: an anonymous
    // individual Therapy's client must never have their real name handed to a third-party
    // provider (security-review finding, 2026-09-11) -- this interface deliberately stays
    // ignorant of Therapy's own anonymity rules, receiving only the name it should actually use.
    // $isOwner affects provider-side capabilities where the provider distinguishes a
    // "host"/"owner" role (Daily's is_owner; Chime has no equivalent concept at the attendee
    // level, ignored there).
    //
    // TT-3.2f-d/SCRUM-321: $receiveOnly mints a participant who can watch/listen but never
    // publish their own audio/video -- SERVER-enforced by the provider itself (Daily's
    // `permissions.canSend`, Chime's attendee `Capabilities`), never a client-side-only
    // restriction the browser could bypass. Resolved by the caller (JoinVideoSessionAction's own
    // isReceiveOnly()), same reasoning as $isOwner/$displayName above -- this interface stays
    // ignorant of GroupTherapy's own authorization rules, receiving only the capability it should
    // actually mint. Mutually exclusive with $isOwner in practice (an owner is never receive-only)
    // but not asserted as such here -- that invariant belongs to the caller, not this contract.
    //
    // Returns a provider-specific, JSON-serializable array handed straight through to the
    // frontend's provider SDK -- never inspected or reshaped by callers of this interface.
    public function createParticipantCredentials(VideoSession $videoSession, User $user, string $displayName, bool $isOwner = false, bool $receiveOnly = false): array;

    // Ends/tears down the room with the provider. Best-effort: providers also expire rooms
    // naturally (Daily via `exp`, Chime meetings end when empty), so a failure here should never
    // block the caller from marking VideoSession.ended_at locally.
    public function endRoom(VideoSession $videoSession): void;

    // TT-3.2b/SCRUM-309: ejects one specific participant from an otherwise-still-open room,
    // without ending it for anyone else -- distinct from endRoom(), which tears the whole room
    // down. Deliberately identifies $user by OUR OWN known id, never a provider-generated
    // participant/attendee id the caller would have to persist -- Daily's own eject endpoint
    // accepts `user_ids` (matching the `user_id` this same interface's createParticipantCredentials()
    // already sends it); Chime has no equivalent "eject by external id" call, so
    // ChimeVideoProvider looks the live attendee up by its own stored ExternalUserId (also
    // $user->id) via ListAttendees before calling DeleteAttendee -- either way, no new
    // provider-specific id needs to be persisted on VideoSessionParticipant.
    //
    // A required interface method, not a separate optional trait (architect decision,
    // 2026-09-14): both current providers support this, and there is no third,
    // ejection-incapable provider on this app's roadmap to justify pre-splitting for.
    //
    // Best-effort, matching endRoom()'s own contract: a provider-side failure here must never
    // block the caller from marking the participant left locally.
    public function removeParticipant(VideoSession $videoSession, User $user): void;
}
