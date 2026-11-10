<?php

use App\Actions\Video\ExpireIdleSpeakingGrantsAction;
use App\Enums\ConstantsEnum;
use App\Enums\SpeakingPermissionRevocationReasonEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\Session;
use App\Models\User;
use App\Models\VideoSession;
use App\Services\Chime\ChimeClient;
use App\Support\VideoParticipantKey;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// TT-3.2f-k/SCRUM-328: the closeout regression suite for full-membership group video (SCRUM-314).
// Every scenario runs through the real HTTP routes and the REAL provider adapters
// (DailyVideoProvider / ChimeVideoProvider) for BOTH providers -- only the transport underneath is
// faked: Daily's REST API via Http::fake(), Chime's AWS SDK wrapper via an in-memory ChimeClient.
// Each fake keeps its own view of "what may this user send", so the assertions are about what the
// provider would be TOLD to enforce, not merely what our database believes. The fakes model each
// provider's protocol as our adapters assume it (e.g. Daily's update-permissions keyed by our
// user_id, which since SCRUM-357 is the per-session participant key, never the user id) -- they can't catch a wrong assumption about the real provider; only a live sandbox pass
// can (SCRUM-317). Per-action edge cases live in the individual action tests; this file pins the
// cross-provider end-to-end behaviour.

// In-memory provider state, shared shape for both fakes: Daily records canSend per participant key
// (from token minting and update-permissions calls) plus ejected keys; Chime keeps live attendees.
function gvrFakeDaily(): object
{
    config([
        'video.provider' => 'daily',
        'services.daily.base_url' => 'https://daily.test/v1',
        'services.daily.domain' => 'talktherapy-test',
        'services.daily.api_key' => 'test-key',
    ]);

    $state = new class
    {
        public string $provider = 'daily';

        public array $canSend = [];

        public array $ejected = [];

        public array $rooms = [];
    };

    Http::fake(function (Request $request) use ($state) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $data = $request->data();

        if ($request->method() === 'POST' && str_ends_with($path, '/rooms')) {
            $state->rooms[] = $data;

            return Http::response(['name' => $data['name'], 'url' => "https://talktherapy-test.daily.co/{$data['name']}"]);
        }

        if (str_ends_with($path, '/meeting-tokens')) {
            $properties = $data['properties'];
            $state->canSend[$properties['user_id']] = $properties['permissions']['canSend'] ?? true;

            return Http::response(['token' => "token-{$properties['user_id']}"]);
        }

        if (str_ends_with($path, '/update-permissions')) {
            foreach ($data['data'] as $participantKey => $permissions) {
                $state->canSend[(string) $participantKey] = $permissions['canSend'];
            }

            return Http::response(['status' => 'sent']);
        }

        if (str_ends_with($path, '/eject')) {
            $state->ejected = [...$state->ejected, ...$data['user_ids']];

            return Http::response(['ejected' => $data['user_ids']]);
        }

        if ($request->method() === 'DELETE' && str_contains($path, '/rooms/')) {
            return Http::response([]);
        }

        // Any endpoint the adapters start calling that this fake doesn't model fails loudly,
        // rather than quietly succeeding and leaving the recorded state unchanged.
        return Http::response(['error' => "unexpected Daily call: {$request->method()} {$path}"], 500);
    });

    return $state;
}

function gvrFakeChime(): object
{
    config(['video.provider' => 'chime']);

    $client = new class extends ChimeClient
    {
        public string $provider = 'chime';

        public array $attendees = [];

        public array $deletedExternalUserIds = [];

        // Deliberately skips the parent constructor -- no AWS SDK client is ever built.
        public function __construct() {}

        public function createMeeting(array $args): array
        {
            return ['Meeting' => ['MeetingId' => 'meeting-'.$args['ExternalMeetingId']]];
        }

        // Like the real service, a second CreateAttendee for the same ExternalUserId returns the
        // existing attendee (capabilities unchanged) rather than a new one.
        public function createAttendee(array $args): array
        {
            $existing = collect($this->attendees)->firstWhere('ExternalUserId', $args['ExternalUserId']);
            if ($existing) {
                return ['Attendee' => $existing];
            }

            $attendee = [
                'AttendeeId' => 'attendee-'.(count($this->attendees) + 1),
                'ExternalUserId' => $args['ExternalUserId'],
                'Capabilities' => $args['Capabilities'] ?? ['Audio' => 'SendReceive', 'Video' => 'SendReceive', 'Content' => 'SendReceive'],
            ];
            $this->attendees[$attendee['AttendeeId']] = $attendee;

            return ['Attendee' => $attendee];
        }

        public function deleteMeeting(string $meetingId): void {}

        public function listAttendees(string $meetingId): array
        {
            return ['Attendees' => array_values($this->attendees)];
        }

        public function deleteAttendee(string $meetingId, string $attendeeId): void
        {
            $this->deletedExternalUserIds[] = $this->attendees[$attendeeId]['ExternalUserId'];
            unset($this->attendees[$attendeeId]);
        }

        public function updateAttendeeCapabilities(string $meetingId, string $attendeeId, array $capabilities): void
        {
            $this->attendees[$attendeeId]['Capabilities'] = $capabilities;
        }
    };

    app()->instance(ChimeClient::class, $client);

    return $client;
}

function gvrFakeProvider(string $provider): object
{
    return $provider === 'daily' ? gvrFakeDaily() : gvrFakeChime();
}

// What the provider has been told to let this user publish right now. A user the provider has never
// heard of is a test bug, not "can't send" -- it fails rather than passing a negative assertion.
// SCRUM-357: the provider only ever knows the per-session participant key -- the real user id must
// never reach it, since every client in the room can read it.
function gvrCanSendAtProvider(object $fake, Session $session, User $user): array
{
    $key = VideoParticipantKey::for($session->id, $user->id);

    if ($fake->provider === 'daily') {
        expect($fake->canSend)->toHaveKey($key)
            ->and($fake->canSend)->not->toHaveKey((string) $user->id);
        $canSend = $fake->canSend[$key];

        return [
            'audio' => $canSend === true || (is_array($canSend) && in_array('audio', $canSend, true)),
            'video' => $canSend === true || (is_array($canSend) && in_array('video', $canSend, true)),
        ];
    }

    // firstWhere, the same lookup ChimeVideoProvider itself uses.
    $attendee = collect($fake->attendees)->firstWhere('ExternalUserId', $key);
    expect($attendee)->not->toBeNull()
        ->and(collect($fake->attendees)->firstWhere('ExternalUserId', (string) $user->id))->toBeNull();
    $sends = fn (?string $capability) => in_array($capability, ['Send', 'SendReceive'], true);

    return [
        'audio' => $sends($attendee['Capabilities']['Audio']),
        'video' => $sends($attendee['Capabilities']['Video']),
    ];
}

function gvrWasEjected(object $fake, Session $session, User $user): bool
{
    return in_array(
        VideoParticipantKey::for($session->id, $user->id),
        $fake->provider === 'daily' ? $fake->ejected : $fake->deletedExternalUserIds,
        true
    );
}

function gvrGroupCall(): array
{
    $creator = User::factory()->adult()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
        'public' => true,
        'anonymous' => false,
    ]);

    $counsellorUsers = [];
    foreach (range(1, 2) as $_) {
        $counsellorUser = User::factory()->create();
        $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
        $groupTherapy->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
        $counsellorUsers[] = $counsellorUser;
    }

    $member = User::factory()->adult()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false, 'was_minor_at_join' => false]);
    $minorMember = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);
    // SCRUM-288: with a guardian -- a minor without one is paused (can't join at all).
    Guardianship::query()->create(['guardian_id' => User::factory()->adult()->create()->id, 'ward_id' => $minorMember->id]);
    $groupTherapy->users()->attach($minorMember->id, ['anonymous' => false, 'was_minor_at_join' => true]);
    $anonymousMember = User::factory()->adult()->create();
    $groupTherapy->users()->attach($anonymousMember->id, ['anonymous' => true, 'was_minor_at_join' => false]);

    $session = Session::factory()->create([
        'for_id' => $groupTherapy->id,
        'for_type' => GroupTherapy::class,
        'type' => 'ONLINE',
        'status' => 'IN_SESSION',
        'start_time' => now(),
    ]);

    return [
        'session' => $session,
        'groupTherapy' => $groupTherapy,
        'creator' => $creator,
        'counsellorA' => $counsellorUsers[0],
        'counsellorB' => $counsellorUsers[1],
        'member' => $member,
        'minorMember' => $minorMember,
        'anonymousMember' => $anonymousMember,
    ];
}

function gvrJoin($test, Session $session, User $user)
{
    return $test->actingAs($user)->postJson(route('sessions.video.join', $session->id));
}

function gvrPost($test, string $routeName, Session $session, User $actingUser, ?User $target = null)
{
    $parameters = $target
        ? ['sessionId' => $session->id, 'participantKey' => VideoParticipantKey::for($session->id, $target->id)]
        : $session->id;

    return $test->actingAs($actingUser)->postJson(route($routeName, $parameters));
}

dataset('video providers', ['daily', 'chime']);

test('an ordinary member joins receive-only at the provider while counsellors and the creator can send', function (string $provider) {
    $fake = gvrFakeProvider($provider);
    $data = gvrGroupCall();

    gvrJoin($this, $data['session'], $data['counsellorA'])->assertOk()->assertJsonPath('participant.canModerate', true);
    gvrJoin($this, $data['session'], $data['creator'])->assertOk()->assertJsonPath('participant.receiveOnly', false);
    gvrJoin($this, $data['session'], $data['member'])->assertOk()
        ->assertJsonPath('participant.receiveOnly', true)
        ->assertJsonPath('participant.canRaiseHand', true);
    gvrJoin($this, $data['session'], $data['anonymousMember'])->assertOk()->assertJsonPath('participant.receiveOnly', true);

    expect(gvrCanSendAtProvider($fake, $data['session'], $data['member']))->toBe(['audio' => false, 'video' => false])
        ->and(gvrCanSendAtProvider($fake, $data['session'], $data['anonymousMember']))->toBe(['audio' => false, 'video' => false])
        ->and(gvrCanSendAtProvider($fake, $data['session'], $data['counsellorA']))->toBe(['audio' => true, 'video' => true])
        ->and(gvrCanSendAtProvider($fake, $data['session'], $data['creator']))->toBe(['audio' => true, 'video' => true]);
})->with('video providers');

test('raise hand, a grant from one counsellor, then a revoke by another, each change what the provider allows', function (string $provider) {
    $fake = gvrFakeProvider($provider);
    $data = gvrGroupCall();
    gvrJoin($this, $data['session'], $data['counsellorA'])->assertOk();
    gvrJoin($this, $data['session'], $data['member'])->assertOk();

    gvrPost($this, 'sessions.video.hand.raise', $data['session'], $data['member'])->assertOk();
    gvrJoin($this, $data['session'], $data['counsellorB'])->assertOk()
        ->assertJsonPath('participant.raisedHandKeys', [VideoParticipantKey::for($data['session']->id, $data['member']->id)]);

    gvrPost($this, 'sessions.video.participants.grant_speaking', $data['session'], $data['counsellorA'], $data['member'])->assertOk();

    $participant = VideoSession::query()->where('session_id', $data['session']->id)->first()->activeParticipantFor($data['member']->id);
    expect(gvrCanSendAtProvider($fake, $data['session'], $data['member']))->toBe(['audio' => true, 'video' => true])
        ->and($participant->currentHandRaise())->toBeNull()
        ->and($participant->currentSpeakingGrant())->not->toBeNull();

    // Peer model: a DIFFERENT counsellor revokes the first one's grant.
    gvrPost($this, 'sessions.video.participants.revoke_speaking', $data['session'], $data['counsellorB'], $data['member'])->assertOk();

    expect(gvrCanSendAtProvider($fake, $data['session'], $data['member']))->toBe(['audio' => false, 'video' => false])
        ->and($participant->fresh()->currentSpeakingGrant())->toBeNull();

    // Lowering your own hand round-trips too.
    gvrPost($this, 'sessions.video.hand.raise', $data['session'], $data['member'])->assertOk();
    gvrPost($this, 'sessions.video.hand.lower', $data['session'], $data['member'])->assertOk();
    expect($participant->fresh()->currentHandRaise())->toBeNull();
})->with('video providers');

// The user's decision (2026-11-05): a minor member can't join group video at all until group
// consent exists (SCRUM-313) -- even with a guardian (this one has one). Speaking stays blocked
// behind that too (EnsureMemberMayBeGrantedSpeakingAction).
test('a minor member is refused group video, so can neither raise a hand nor be granted speaking', function (string $provider) {
    gvrFakeProvider($provider);
    $data = gvrGroupCall();
    gvrJoin($this, $data['session'], $data['counsellorA'])->assertOk();

    gvrJoin($this, $data['session'], $data['minorMember'])->assertStatus(422)
        ->assertJsonPath('message', "Video calls in a group need a parent or guardian's consent first.");

    gvrPost($this, 'sessions.video.hand.raise', $data['session'], $data['minorMember'])->assertStatus(422);
    gvrPost($this, 'sessions.video.participants.grant_speaking', $data['session'], $data['counsellorA'], $data['minorMember'])->assertStatus(422);
})->with('video providers');

test('an anonymous member is granted audio only, a non-anonymous member audio and video', function (string $provider) {
    $fake = gvrFakeProvider($provider);
    $data = gvrGroupCall();
    gvrJoin($this, $data['session'], $data['counsellorA'])->assertOk();
    gvrJoin($this, $data['session'], $data['anonymousMember'])->assertOk()->assertJsonPath('participant.isAnonymous', true);
    gvrJoin($this, $data['session'], $data['member'])->assertOk()->assertJsonPath('participant.isAnonymous', false);

    gvrPost($this, 'sessions.video.participants.grant_speaking', $data['session'], $data['counsellorA'], $data['anonymousMember'])->assertOk();
    gvrPost($this, 'sessions.video.participants.grant_speaking', $data['session'], $data['counsellorA'], $data['member'])->assertOk();

    expect(gvrCanSendAtProvider($fake, $data['session'], $data['anonymousMember']))->toBe(['audio' => true, 'video' => false])
        ->and(gvrCanSendAtProvider($fake, $data['session'], $data['member']))->toBe(['audio' => true, 'video' => true]);
})->with('video providers');

test('the creator of an anonymous group joins audio-only at the provider', function (string $provider) {
    $fake = gvrFakeProvider($provider);
    $data = gvrGroupCall();
    $data['groupTherapy']->update(['anonymous' => true]);

    gvrJoin($this, $data['session'], $data['creator'])->assertOk()
        ->assertJsonPath('participant.canSendAudio', true)
        ->assertJsonPath('participant.canSendVideo', false);

    expect(gvrCanSendAtProvider($fake, $data['session'], $data['creator']))->toBe(['audio' => true, 'video' => false]);
})->with('video providers');

test('an unused grant is reverted at the provider by the idle sweep, while an active speaker keeps theirs', function (string $provider) {
    $fake = gvrFakeProvider($provider);
    $data = gvrGroupCall();
    gvrJoin($this, $data['session'], $data['counsellorA'])->assertOk();
    gvrJoin($this, $data['session'], $data['member'])->assertOk();
    gvrJoin($this, $data['session'], $data['anonymousMember'])->assertOk();
    gvrPost($this, 'sessions.video.participants.grant_speaking', $data['session'], $data['counsellorA'], $data['member'])->assertOk();
    gvrPost($this, 'sessions.video.participants.grant_speaking', $data['session'], $data['counsellorA'], $data['anonymousMember'])->assertOk();

    $this->travel((int) config('video.speaking_grant_idle_timeout') + 1)->seconds();
    // Only the anonymous member's client reports activity.
    gvrPost($this, 'sessions.video.speaking_activity', $data['session'], $data['anonymousMember'])->assertOk();

    ExpireIdleSpeakingGrantsAction::new()->execute();

    $videoSession = VideoSession::query()->where('session_id', $data['session']->id)->first();
    $idleGrant = $videoSession->activeParticipantFor($data['member']->id)->speakingGrants()->latest('id')->first();
    expect(gvrCanSendAtProvider($fake, $data['session'], $data['member']))->toBe(['audio' => false, 'video' => false])
        ->and($idleGrant->revocation_reason)->toBe(SpeakingPermissionRevocationReasonEnum::idle_auto_expiry->value)
        ->and(gvrCanSendAtProvider($fake, $data['session'], $data['anonymousMember']))->toBe(['audio' => true, 'video' => false]);
})->with('video providers');

test('removing a participant clears their hand-raise and speaking grant and ejects them at the provider', function (string $provider) {
    $fake = gvrFakeProvider($provider);
    $data = gvrGroupCall();
    gvrJoin($this, $data['session'], $data['counsellorA'])->assertOk();
    gvrJoin($this, $data['session'], $data['member'])->assertOk();
    gvrJoin($this, $data['session'], $data['anonymousMember'])->assertOk();
    gvrPost($this, 'sessions.video.participants.grant_speaking', $data['session'], $data['counsellorA'], $data['member'])->assertOk();
    gvrPost($this, 'sessions.video.hand.raise', $data['session'], $data['anonymousMember'])->assertOk();

    gvrPost($this, 'sessions.video.participants.remove', $data['session'], $data['counsellorA'], $data['member'])->assertOk();
    gvrPost($this, 'sessions.video.participants.remove', $data['session'], $data['counsellorA'], $data['anonymousMember'])->assertOk();

    $videoSession = VideoSession::query()->where('session_id', $data['session']->id)->first();
    $memberRow = $videoSession->participantRowsFor($data['member']->id)->latest('id')->first();
    $anonymousRow = $videoSession->participantRowsFor($data['anonymousMember']->id)->latest('id')->first();

    expect($memberRow->left_at)->not->toBeNull()
        ->and($memberRow->currentSpeakingGrant())->toBeNull()
        ->and($memberRow->speakingGrants()->latest('id')->first()->revocation_reason)
        ->toBe(SpeakingPermissionRevocationReasonEnum::participant_removed->value)
        ->and($anonymousRow->currentHandRaise())->toBeNull()
        ->and(gvrWasEjected($fake, $data['session'], $data['member']))->toBeTrue()
        ->and(gvrWasEjected($fake, $data['session'], $data['anonymousMember']))->toBeTrue();

    // And a later counsellor joining sees nothing left over for them.
    gvrJoin($this, $data['session'], $data['counsellorB'])->assertOk()
        ->assertJsonPath('participant.raisedHandKeys', [])
        ->assertJsonPath('participant.speakerKeys', []);
})->with('video providers');

// The unified cap (SCRUM-318): one number for group membership and the video room. Daily enforces it
// natively at room creation; Chime has no room-size parameter, so the app enforces it at join.
// Membership-side enforcement of the same 25 is covered by EnsureTherapyDataIsValidAction's own tests.
test('the group video cap is pinned at 25', function () {
    expect((int) ConstantsEnum::groupTherapyVideoMaxParticipants->value)->toBe(25);
});

test('Daily is given the group cap as the room\'s own max_participants', function () {
    $fake = gvrFakeDaily();
    $data = gvrGroupCall();

    gvrJoin($this, $data['session'], $data['counsellorA'])->assertOk();

    expect($fake->rooms[0]['properties']['max_participants'])->toBe((int) ConstantsEnum::groupTherapyVideoMaxParticipants->value);
});

test('Chime refuses a join once the call already holds the group cap of active participants', function () {
    gvrFakeChime();
    $data = gvrGroupCall();
    gvrJoin($this, $data['session'], $data['counsellorA'])->assertOk();

    $videoSession = VideoSession::query()->where('session_id', $data['session']->id)->first();
    $cap = (int) ConstantsEnum::groupTherapyVideoMaxParticipants->value;
    // The counsellor plus cap-2 others: exactly one slot left.
    foreach (range(1, $cap - 2) as $_) {
        $videoSession->participants()->create([
            'participant_type' => User::class,
            'participant_id' => User::factory()->create()->id,
            'joined_at' => now(),
        ]);
    }

    // The cap-th participant still gets in...
    gvrJoin($this, $data['session'], $data['member'])->assertOk();
    // ...the next one does not.
    gvrJoin($this, $data['session'], $data['anonymousMember'])->assertStatus(422)
        ->assertJsonPath('message', 'This video call has reached its maximum number of participants.');
});
