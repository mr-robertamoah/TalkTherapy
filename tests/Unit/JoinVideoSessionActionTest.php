<?php

use App\Actions\Video\JoinVideoSessionAction;
use App\Contracts\VideoProviderInterface;
use App\Enums\ConstantsEnum;
use App\Events\VideoSessionStatusChangedEvent;
use App\Exceptions\VideoException;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoSession;
use App\Models\VideoSessionParticipant;
use App\Models\VideoSessionSpeakingGrant;
use App\Support\VideoParticipantKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

// TT-3.1a/SCRUM-274: the VideoProviderInterface is swapped for a fake in the container throughout
// -- this file pins down JoinVideoSessionAction's own orchestration, independent of any real
// provider's API.

function fakeVideoProvider(): VideoProviderInterface
{
    return new class implements VideoProviderInterface
    {
        public array $createRoomCalls = [];

        public array $createParticipantCredentialsCalls = [];

        public function createRoom(VideoSession $videoSession): array
        {
            $this->createRoomCalls[] = $videoSession->id;

            return ['room_id' => "fake-room-{$videoSession->id}", 'meta' => ['fake' => true]];
        }

        public function createParticipantCredentials(VideoSession $videoSession, User $user, string $displayName, bool $receiveOnly = false, bool $canSendVideo = true): array
        {
            $this->createParticipantCredentialsCalls[] = [$videoSession->id, $user->id, $displayName, $receiveOnly, $canSendVideo];

            return ['token' => "fake-token-{$user->id}"];
        }

        public function endRoom(VideoSession $videoSession): void {}

        public function removeParticipant(VideoSession $videoSession, User $user): void {}

        public function updateParticipantCapabilities(VideoSession $videoSession, User $user, bool $canSendAudio, bool $canSendVideo): void {}
    };
}

function onlineInSessionTherapySessionForJoin(array $overrides = []): Session
{
    $client = User::factory()->adult()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
    ]);

    return Session::factory()->create(array_merge([
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
        'type' => 'ONLINE',
        'status' => 'IN_SESSION',
        'start_time' => now(),
    ], $overrides));
}

test('joining creates a new VideoSession, calls the provider to create a room, and records the participant', function () {
    Event::fake();
    $session = onlineInSessionTherapySessionForJoin();
    $client = $session->for->addedby;
    $fakeProvider = fakeVideoProvider();
    app()->instance(VideoProviderInterface::class, $fakeProvider);

    $credentials = JoinVideoSessionAction::new()->execute($session, $client);

    $videoSession = VideoSession::query()->where('session_id', $session->id)->first();
    expect($videoSession)->not->toBeNull()
        ->and($videoSession->provider_room_id)->toBe("fake-room-{$videoSession->id}")
        ->and($fakeProvider->createRoomCalls)->toBe([$videoSession->id])
        ->and($credentials['token'])->toBe("fake-token-{$client->id}")
        ->and($credentials['participant'])->toBe([
            'receiveOnly' => false,
            'canSendAudio' => true,
            'canSendVideo' => true,
            'isAnonymous' => false,
            'canRaiseHand' => false,
            'speakingNote' => null,
            'canModerate' => false,
            'speakingGrantIdleTimeout' => (int) config('video.speaking_grant_idle_timeout'),
            'key' => VideoParticipantKey::for($session->id, $client->id),
            'raisedHandKeys' => [],
            'speakerKeys' => [],
        ]);

    $this->assertDatabaseHas('video_session_participants', [
        'video_session_id' => $videoSession->id,
        'participant_type' => User::class,
        'participant_id' => $client->id,
    ]);

    Event::assertDispatched(VideoSessionStatusChangedEvent::class);
});

test('a second join by a different participant reuses the same VideoSession rather than creating a new room', function () {
    $session = onlineInSessionTherapySessionForJoin();
    $client = $session->for->addedby;
    $counsellorUser = $session->for->counsellor->user;
    $fakeProvider = fakeVideoProvider();
    app()->instance(VideoProviderInterface::class, $fakeProvider);

    JoinVideoSessionAction::new()->execute($session, $client);
    JoinVideoSessionAction::new()->execute($session, $counsellorUser);

    expect(VideoSession::query()->where('session_id', $session->id)->count())->toBe(1)
        ->and($fakeProvider->createRoomCalls)->toHaveCount(1);

    $this->assertDatabaseHas('video_session_participants', [
        'participant_id' => $counsellorUser->id,
    ]);
});

// SCRUM-380 (deliberate change): the assigned counsellor used to join as the provider-side owner.
// No one is an owner now (VideoProviderInterface has no owner flag); both sides are full participants.
test('the assigned counsellor and the client both join as full participants', function () {
    $session = onlineInSessionTherapySessionForJoin();
    $client = $session->for->addedby;
    $counsellorUser = $session->for->counsellor->user;
    $fakeProvider = fakeVideoProvider();
    app()->instance(VideoProviderInterface::class, $fakeProvider);

    JoinVideoSessionAction::new()->execute($session, $client);
    JoinVideoSessionAction::new()->execute($session, $counsellorUser);

    [, , , $clientReceiveOnly, $clientCanSendVideo] = $fakeProvider->createParticipantCredentialsCalls[0];
    [, , , $counsellorReceiveOnly, $counsellorCanSendVideo] = $fakeProvider->createParticipantCredentialsCalls[1];

    expect($clientReceiveOnly)->toBeFalse()
        ->and($clientCanSendVideo)->toBeTrue()
        ->and($counsellorReceiveOnly)->toBeFalse()
        ->and($counsellorCanSendVideo)->toBeTrue();
});

test('joining is blocked by the same availability gate as EnsureVideoIsAvailableForSessionAction', function () {
    $session = onlineInSessionTherapySessionForJoin(['status' => 'PENDING']);
    $client = $session->for->addedby;
    app()->instance(VideoProviderInterface::class, fakeVideoProvider());

    expect(fn () => JoinVideoSessionAction::new()->execute($session, $client))
        ->toThrow(VideoException::class);

    $this->assertDatabaseCount('video_sessions', 0);
});

// TT-3.1c/SCRUM-276 QA finding: createRoom() was unguarded, so a real provider HTTP failure
// (Illuminate\Http\Client\RequestException, whose getCode() equals the upstream's own status,
// not 500) sailed straight past ResolvesExceptionResponse::messageFor()'s 500-only masking and
// leaked the provider's raw response body to the end user.
test('a provider room-creation failure surfaces a safe, generic message, never the raw provider error', function () {
    Log::shouldReceive('warning')->once();
    $session = onlineInSessionTherapySessionForJoin();
    $client = $session->for->addedby;
    app()->instance(VideoProviderInterface::class, new class implements VideoProviderInterface
    {
        public function createRoom(VideoSession $videoSession): array
        {
            throw new RuntimeException('{"error":"authorization-header-error","info":"invalid authorization header"}');
        }

        public function createParticipantCredentials(VideoSession $videoSession, User $user, string $displayName, bool $receiveOnly = false, bool $canSendVideo = true): array
        {
            return [];
        }

        public function endRoom(VideoSession $videoSession): void {}

        public function removeParticipant(VideoSession $videoSession, User $user): void {}

        public function updateParticipantCapabilities(VideoSession $videoSession, User $user, bool $canSendAudio, bool $canSendVideo): void {}
    });

    try {
        JoinVideoSessionAction::new()->execute($session, $client);
        $this->fail('Expected a VideoException to be thrown.');
    } catch (VideoException $exception) {
        expect($exception->getMessage())
            ->toBe('Unable to start the video call right now. Please try again shortly.')
            ->not->toContain('authorization-header-error');
    }

    // The transaction rolls back the whole find-or-create -- no half-created VideoSession left
    // behind for a future join to stumble over.
    $this->assertDatabaseCount('video_sessions', 0);
});

// SCRUM-335: the same leak through token minting, plus the participant row opened for a join that
// never happened.
test('a provider credential failure surfaces a generic 502 and closes the participant row it opened', function () {
    Log::shouldReceive('warning')->once();
    $session = onlineInSessionTherapySessionForJoin();
    $client = $session->for->addedby;
    app()->instance(VideoProviderInterface::class, new class implements VideoProviderInterface
    {
        public function createRoom(VideoSession $videoSession): array
        {
            return ['room_id' => 'room-'.$videoSession->id, 'meta' => []];
        }

        public function createParticipantCredentials(VideoSession $videoSession, User $user, string $displayName, bool $receiveOnly = false, bool $canSendVideo = true): array
        {
            throw new RuntimeException('{"error":"authorization-header-error","info":"invalid authorization header"}', 400);
        }

        public function endRoom(VideoSession $videoSession): void {}

        public function removeParticipant(VideoSession $videoSession, User $user): void {}

        public function updateParticipantCapabilities(VideoSession $videoSession, User $user, bool $canSendAudio, bool $canSendVideo): void {}
    });

    try {
        JoinVideoSessionAction::new()->execute($session, $client);
        $this->fail('Expected a VideoException to be thrown.');
    } catch (VideoException $exception) {
        expect($exception->getCode())->toBe(502)
            ->and($exception->getMessage())->not->toContain('authorization-header-error');
    }

    $rows = VideoSessionParticipant::query()->where('participant_id', $client->id)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->left_at)->not->toBeNull();
});

// A room that has already ended (e.g. a prior call was explicitly ended) must not be silently
// rejoined -- a fresh join after an end should start a genuinely new epoch/room.
test('joining after the video session has ended creates a fresh VideoSession, not the ended one', function () {
    $session = onlineInSessionTherapySessionForJoin();
    $client = $session->for->addedby;
    $fakeProvider = fakeVideoProvider();
    app()->instance(VideoProviderInterface::class, $fakeProvider);

    $endedVideoSession = VideoSession::factory()->create([
        'session_id' => $session->id,
        'ended_at' => now()->subMinute(),
    ]);

    JoinVideoSessionAction::new()->execute($session, $client);

    $newVideoSession = VideoSession::query()->where('session_id', $session->id)->whereNull('ended_at')->first();
    expect($newVideoSession)->not->toBeNull()
        ->and($newVideoSession->id)->not->toBe($endedVideoSession->id);
});

// Security-review finding (2026-09-11): the joining client's real name must never reach the
// provider (and therefore the counsellor's on-screen video label) when the therapy is anonymous.
test('an anonymous therapy\'s client joins video under the anonymous label, never their real name', function () {
    $session = onlineInSessionTherapySessionForJoin();
    $session->for->update(['anonymous' => true]);
    $client = $session->for->addedby;
    $fakeProvider = fakeVideoProvider();
    app()->instance(VideoProviderInterface::class, $fakeProvider);

    JoinVideoSessionAction::new()->execute($session, $client);

    [, , $displayName] = $fakeProvider->createParticipantCredentialsCalls[0];
    expect($displayName)->toBe(ConstantsEnum::anonymousUserLabel->value)
        ->and($displayName)->not->toBe($client->name);
});

// The counsellor's own identity is never masked -- anonymity only ever applies to the client
// (TherapyTrait::addedByUserIsMaskedFor()'s own established rule).
test('an anonymous therapy\'s counsellor still joins video under their own real name', function () {
    $session = onlineInSessionTherapySessionForJoin();
    $session->for->update(['anonymous' => true]);
    $counsellorUser = $session->for->counsellor->user;
    $fakeProvider = fakeVideoProvider();
    app()->instance(VideoProviderInterface::class, $fakeProvider);

    JoinVideoSessionAction::new()->execute($session, $counsellorUser);

    [, , $displayName] = $fakeProvider->createParticipantCredentialsCalls[0];
    expect($displayName)->toBe($counsellorUser->name);
});

// A non-anonymous therapy's client joins under their own real name, unaffected by this fix.
// TherapyFactory defaults `anonymous` to true, so this must override it explicitly.
test('a non-anonymous therapy\'s client joins video under their own real name', function () {
    $session = onlineInSessionTherapySessionForJoin();
    $session->for->update(['anonymous' => false]);
    $client = $session->for->addedby;
    $fakeProvider = fakeVideoProvider();
    app()->instance(VideoProviderInterface::class, $fakeProvider);

    JoinVideoSessionAction::new()->execute($session, $client);

    [, , $displayName] = $fakeProvider->createParticipantCredentialsCalls[0];
    expect($displayName)->toBe($client->name);
});

// TT-3.2f-d/SCRUM-321: an ordinary GroupTherapy member now joins receive-only rather than being
// denied entirely.

function onlineInSessionGroupTherapySessionForJoin(): array
{
    $creator = User::factory()->adult()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
        'public' => true,
    ]);
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $member = User::factory()->adult()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false]);
    $session = Session::factory()->create([
        'for_id' => $groupTherapy->id,
        'for_type' => GroupTherapy::class,
        'type' => 'ONLINE',
        'status' => 'IN_SESSION',
        'start_time' => now(),
    ]);

    return compact('creator', 'groupTherapy', 'counsellorUser', 'member', 'session');
}

test('an ordinary GroupTherapy member joins receive-only', function () {
    $data = onlineInSessionGroupTherapySessionForJoin();
    $fakeProvider = fakeVideoProvider();
    app()->instance(VideoProviderInterface::class, $fakeProvider);

    JoinVideoSessionAction::new()->execute($data['session'], $data['member']);

    [, , , $receiveOnly] = $fakeProvider->createParticipantCredentialsCalls[0];
    expect($receiveOnly)->toBeTrue();
});

test('an active counsellor on a GroupTherapy joins with full access, not receive-only', function () {
    $data = onlineInSessionGroupTherapySessionForJoin();
    $fakeProvider = fakeVideoProvider();
    app()->instance(VideoProviderInterface::class, $fakeProvider);

    JoinVideoSessionAction::new()->execute($data['session'], $data['counsellorUser']);

    [, , , $receiveOnly] = $fakeProvider->createParticipantCredentialsCalls[0];
    expect($receiveOnly)->toBeFalse();
});

test('the group\'s own creator joins with full access, not receive-only', function () {
    $data = onlineInSessionGroupTherapySessionForJoin();
    $fakeProvider = fakeVideoProvider();
    app()->instance(VideoProviderInterface::class, $fakeProvider);

    JoinVideoSessionAction::new()->execute($data['session'], $data['creator']);

    [, , , $receiveOnly] = $fakeProvider->createParticipantCredentialsCalls[0];
    expect($receiveOnly)->toBeFalse();
});

test('a 1:1 Therapy client never joins receive-only, regardless of role', function () {
    $session = onlineInSessionTherapySessionForJoin();
    $client = $session->for->addedby;
    $fakeProvider = fakeVideoProvider();
    app()->instance(VideoProviderInterface::class, $fakeProvider);

    JoinVideoSessionAction::new()->execute($session, $client);

    [, , , $receiveOnly] = $fakeProvider->createParticipantCredentialsCalls[0];
    expect($receiveOnly)->toBeFalse();
});

// TT-3.2f-d/SCRUM-321: Chime has no native provider-level room-size cap (unlike Daily's own
// max_participants), so the group video cap is enforced in application code, inside the same lock
// that already serializes concurrent joins, specifically (and only) when Chime is the active
// provider for a GroupTherapy session.

test('Chime enforces the group video participant cap in application code', function () {
    Config::set('video.provider', 'chime');
    $data = onlineInSessionGroupTherapySessionForJoin();
    app()->instance(VideoProviderInterface::class, fakeVideoProvider());
    $videoSession = VideoSession::factory()->create(['session_id' => $data['session']->id, 'provider' => 'chime']);
    // Fill the room to exactly the cap with already-active participants.
    VideoSessionParticipant::factory()->count((int) ConstantsEnum::groupTherapyVideoMaxParticipants->value)->create([
        'video_session_id' => $videoSession->id,
        'left_at' => null,
    ]);

    expect(fn () => JoinVideoSessionAction::new()->execute($data['session'], $data['member']))
        ->toThrow(VideoException::class, 'This video call has reached its maximum number of participants.');
});

test('Chime does not enforce the group video cap for a 1:1 Therapy session', function () {
    Config::set('video.provider', 'chime');
    $session = onlineInSessionTherapySessionForJoin();
    $client = $session->for->addedby;
    app()->instance(VideoProviderInterface::class, fakeVideoProvider());

    expect(fn () => JoinVideoSessionAction::new()->execute($session, $client))
        ->not->toThrow(VideoException::class);
});

test('Daily does not duplicate its own provider-level cap with an application-level check', function () {
    Config::set('video.provider', 'daily');
    $data = onlineInSessionGroupTherapySessionForJoin();
    app()->instance(VideoProviderInterface::class, fakeVideoProvider());
    $videoSession = VideoSession::factory()->create(['session_id' => $data['session']->id, 'provider' => 'daily']);
    VideoSessionParticipant::factory()->count((int) ConstantsEnum::groupTherapyVideoMaxParticipants->value)->create([
        'video_session_id' => $videoSession->id,
        'left_at' => null,
    ]);

    expect(fn () => JoinVideoSessionAction::new()->execute($data['session'], $data['member']))
        ->not->toThrow(VideoException::class);
});

// TT-3.2f-g/SCRUM-324 review finding: an anonymous ORDINARY group member (not just the creator) must
// join under the anonymous label -- mirrors routes/channels.php's own group masking.
test('an ordinary GroupTherapy member with their own anonymous flag joins under the anonymous label', function () {
    $data = onlineInSessionGroupTherapySessionForJoin();
    $data['groupTherapy']->update(['anonymous' => false]);
    $data['groupTherapy']->users()->updateExistingPivot($data['member']->id, ['anonymous' => true]);
    $fakeProvider = fakeVideoProvider();
    app()->instance(VideoProviderInterface::class, $fakeProvider);

    JoinVideoSessionAction::new()->execute($data['session'], $data['member']);

    [, , $displayName] = $fakeProvider->createParticipantCredentialsCalls[0];
    expect($displayName)->toBe(ConstantsEnum::anonymousUserLabel->value);
});

test('every ordinary member of an anonymous group joins under the anonymous label', function () {
    $data = onlineInSessionGroupTherapySessionForJoin();
    $data['groupTherapy']->update(['anonymous' => true]);
    $fakeProvider = fakeVideoProvider();
    app()->instance(VideoProviderInterface::class, $fakeProvider);

    JoinVideoSessionAction::new()->execute($data['session'], $data['member']);

    [, , $displayName] = $fakeProvider->createParticipantCredentialsCalls[0];
    expect($displayName)->toBe(ConstantsEnum::anonymousUserLabel->value);
});

test('a non-anonymous member of a non-anonymous group joins under their real name', function () {
    $data = onlineInSessionGroupTherapySessionForJoin();
    $data['groupTherapy']->update(['anonymous' => false]);
    $fakeProvider = fakeVideoProvider();
    app()->instance(VideoProviderInterface::class, $fakeProvider);

    JoinVideoSessionAction::new()->execute($data['session'], $data['member']);

    [, , $displayName] = $fakeProvider->createParticipantCredentialsCalls[0];
    expect($displayName)->toBe($data['member']->name);
});

test('a counsellor of an anonymous group still joins under their real name', function () {
    $data = onlineInSessionGroupTherapySessionForJoin();
    $data['groupTherapy']->update(['anonymous' => true]);
    $fakeProvider = fakeVideoProvider();
    app()->instance(VideoProviderInterface::class, $fakeProvider);

    JoinVideoSessionAction::new()->execute($data['session'], $data['counsellorUser']);

    [, , $displayName] = $fakeProvider->createParticipantCredentialsCalls[0];
    expect($displayName)->toBe($data['counsellorUser']->name);
});

// SCRUM-331: no Daily owner tokens in group calls -- an owner's browser could change any
// participant's permissions client-side, bypassing the server-enforced minor/anonymity rules.
test('a GroupTherapy counsellor never joins as a provider-side owner', function () {
    $data = onlineInSessionGroupTherapySessionForJoin();
    $fakeProvider = fakeVideoProvider();
    app()->instance(VideoProviderInterface::class, $fakeProvider);

    JoinVideoSessionAction::new()->execute($data['session'], $data['counsellorUser']);

    [, , , $receiveOnly, $canSendVideo] = $fakeProvider->createParticipantCredentialsCalls[0];
    expect($receiveOnly)->toBeFalse()
        ->and($canSendVideo)->toBeTrue();
});

// SCRUM-380: there is no owner flag left to pass -- the contract itself can't mint an owner.
test('the video provider contract has no owner parameter', function () {
    $parameters = collect((new ReflectionMethod(VideoProviderInterface::class, 'createParticipantCredentials'))->getParameters())
        ->map(fn ($parameter) => $parameter->getName())
        ->all();

    expect($parameters)->toBe(['videoSession', 'user', 'displayName', 'receiveOnly', 'canSendVideo']);
});

// SCRUM-332: the creator of an anonymous group joins audio-only -- their face is never shown.
test('the creator of an anonymous group joins audio-only', function () {
    $data = onlineInSessionGroupTherapySessionForJoin();
    $data['groupTherapy']->update(['anonymous' => true]);
    $fakeProvider = fakeVideoProvider();
    app()->instance(VideoProviderInterface::class, $fakeProvider);

    JoinVideoSessionAction::new()->execute($data['session'], $data['creator']);

    [, , $displayName, $receiveOnly, $canSendVideo] = $fakeProvider->createParticipantCredentialsCalls[0];
    expect($displayName)->toBe(ConstantsEnum::anonymousUserLabel->value)
        ->and($receiveOnly)->toBeFalse()
        ->and($canSendVideo)->toBeFalse();
});

test('the creator of a non-anonymous group keeps their camera', function () {
    $data = onlineInSessionGroupTherapySessionForJoin();
    $data['groupTherapy']->update(['anonymous' => false]);
    $fakeProvider = fakeVideoProvider();
    app()->instance(VideoProviderInterface::class, $fakeProvider);

    JoinVideoSessionAction::new()->execute($data['session'], $data['creator']);

    [, , , , $canSendVideo] = $fakeProvider->createParticipantCredentialsCalls[0];
    expect($canSendVideo)->toBeTrue();
});

test('a counsellor of an anonymous group keeps their camera', function () {
    $data = onlineInSessionGroupTherapySessionForJoin();
    $data['groupTherapy']->update(['anonymous' => true]);
    $fakeProvider = fakeVideoProvider();
    app()->instance(VideoProviderInterface::class, $fakeProvider);

    JoinVideoSessionAction::new()->execute($data['session'], $data['counsellorUser']);

    [, , , , $canSendVideo] = $fakeProvider->createParticipantCredentialsCalls[0];
    expect($canSendVideo)->toBeTrue();
});

// SCRUM-332's audio-only rule is group-only: an anonymous 1:1 Therapy client keeps their camera.
test('an anonymous 1:1 Therapy client still joins with video allowed', function () {
    $session = onlineInSessionTherapySessionForJoin();
    $session->for->update(['anonymous' => true]);
    $fakeProvider = fakeVideoProvider();
    app()->instance(VideoProviderInterface::class, $fakeProvider);

    JoinVideoSessionAction::new()->execute($session, $session->for->addedby);

    [, , , , $canSendVideo] = $fakeProvider->createParticipantCredentialsCalls[0];
    expect($canSendVideo)->toBeTrue();
});

// TT-3.2f-j/SCRUM-327: the `participant` state the call UI starts from.
test('an ordinary member\'s join state is receive-only with a raisable hand', function () {
    $data = onlineInSessionGroupTherapySessionForJoin();
    $data['groupTherapy']->users()->updateExistingPivot($data['member']->id, ['was_minor_at_join' => false]);
    app()->instance(VideoProviderInterface::class, fakeVideoProvider());

    $state = JoinVideoSessionAction::new()->execute($data['session'], $data['member'])['participant'];

    expect($state['receiveOnly'])->toBeTrue()
        ->and($state['canSendAudio'])->toBeFalse()
        ->and($state['canSendVideo'])->toBeFalse()
        ->and($state['canRaiseHand'])->toBeTrue()
        ->and($state['canModerate'])->toBeFalse()
        ->and($state['key'])->toBe(VideoParticipantKey::for($data['session']->id, $data['member']->id))
        ->and($state['raisedHandKeys'])->toBe([])
        ->and($state['speakerKeys'])->toBe([]);
});

// The user's decision (2026-11-05, replacing 2026-09-14's receive-only-for-anyone): a member who
// joined as a minor needs a guardian's video consent first, and group consent doesn't exist yet
// (SCRUM-313) -- so they aren't let in at all, and no participant row is made.
test('a member who is a minor is refused group video, before any credentials are made', function () {
    $data = onlineInSessionGroupTherapySessionForJoin();
    $data['groupTherapy']->users()->updateExistingPivot($data['member']->id, ['was_minor_at_join' => true]);
    $data['member']->update(['dob' => now()->subYears(15)]);
    // with a guardian, so the SCRUM-288 pause isn't what refuses them
    Guardianship::query()->create(['guardian_id' => User::factory()->adult()->create()->id, 'ward_id' => $data['member']->id, 'ward_was_minor_at_creation' => true]);
    app()->instance(VideoProviderInterface::class, fakeVideoProvider());

    expect(fn () => JoinVideoSessionAction::new()->execute($data['session'], $data['member']))
        ->toThrow(VideoException::class, "Video calls in a group need a parent or guardian's consent first.")
        ->and(VideoSessionParticipant::query()->where('participant_id', $data['member']->id)->exists())->toBeFalse();
});

test('an anonymous group\'s creator joins audio-only and flagged anonymous', function () {
    $data = onlineInSessionGroupTherapySessionForJoin();
    $data['groupTherapy']->update(['anonymous' => true]);
    app()->instance(VideoProviderInterface::class, fakeVideoProvider());

    $state = JoinVideoSessionAction::new()->execute($data['session'], $data['creator'])['participant'];

    expect($state['receiveOnly'])->toBeFalse()
        ->and($state['canSendAudio'])->toBeTrue()
        ->and($state['canSendVideo'])->toBeFalse()
        ->and($state['isAnonymous'])->toBeTrue()
        ->and($state['canRaiseHand'])->toBeFalse();
});

test('a counsellor joining mid-call sees the hands and speakers already live in the call', function () {
    $data = onlineInSessionGroupTherapySessionForJoin();
    app()->instance(VideoProviderInterface::class, fakeVideoProvider());
    JoinVideoSessionAction::new()->execute($data['session'], $data['member']);
    $speaker = User::factory()->adult()->create();
    $data['groupTherapy']->users()->attach($speaker->id, ['anonymous' => false]);
    JoinVideoSessionAction::new()->execute($data['session'], $speaker);
    $leaver = User::factory()->adult()->create();
    $data['groupTherapy']->users()->attach($leaver->id, ['anonymous' => false]);
    JoinVideoSessionAction::new()->execute($data['session'], $leaver);

    $videoSession = VideoSession::query()->where('session_id', $data['session']->id)->first();
    $videoSession->activeParticipantFor($data['member']->id)->handRaises()->create(['raised_at' => now()]);
    VideoSessionSpeakingGrant::factory()->create([
        'video_session_participant_id' => $videoSession->activeParticipantFor($speaker->id)->id,
        'revoked_at' => null,
    ]);
    // A hand left open on a row that has since left the call must not show up.
    $leaverRow = $videoSession->activeParticipantFor($leaver->id);
    $leaverRow->handRaises()->create(['raised_at' => now()]);
    $leaverRow->update(['left_at' => now()]);

    $state = JoinVideoSessionAction::new()->execute($data['session'], $data['counsellorUser'])['participant'];

    expect($state['canModerate'])->toBeTrue()
        ->and($state['receiveOnly'])->toBeFalse()
        ->and($state['raisedHandKeys'])->toBe([VideoParticipantKey::for($data['session']->id, $data['member']->id)])
        ->and($state['speakerKeys'])->toBe([VideoParticipantKey::for($data['session']->id, $speaker->id)]);
});

test('the counsellor snapshot ignores a hand left on a stale join row the member has since replaced', function () {
    $data = onlineInSessionGroupTherapySessionForJoin();
    app()->instance(VideoProviderInterface::class, fakeVideoProvider());
    JoinVideoSessionAction::new()->execute($data['session'], $data['member']);
    $videoSession = VideoSession::query()->where('session_id', $data['session']->id)->first();
    $videoSession->activeParticipantFor($data['member']->id)->handRaises()->create(['raised_at' => now()]);
    // A reconnect: a newer open row, without the hand, while the old row was never closed.
    $this->travel(1)->seconds();
    JoinVideoSessionAction::new()->execute($data['session'], $data['member']);

    $state = JoinVideoSessionAction::new()->execute($data['session'], $data['counsellorUser'])['participant'];

    expect($state['raisedHandKeys'])->toBe([]);
});

// --- SCRUM-330: the Chime cap counts people, not join rows ------------------------------------

test('Chime: stale join rows of the same few people never fill the call', function () {
    Config::set('video.provider', 'chime');
    $data = onlineInSessionGroupTherapySessionForJoin();
    app()->instance(VideoProviderInterface::class, fakeVideoProvider());
    $videoSession = VideoSession::factory()->create(['session_id' => $data['session']->id, 'provider' => 'chime']);
    // One person who reconnected over and over: more open rows than the cap, but one person.
    VideoSessionParticipant::factory()->count((int) ConstantsEnum::groupTherapyVideoMaxParticipants->value + 3)->create([
        'video_session_id' => $videoSession->id,
        'participant_type' => User::class,
        'participant_id' => $data['counsellorUser']->id,
        'left_at' => null,
    ]);

    JoinVideoSessionAction::new()->execute($data['session'], $data['member']);

    expect($videoSession->activeParticipantFor($data['member']->id))->not->toBeNull();
});

test('Chime: someone already in a full call can reconnect', function () {
    Config::set('video.provider', 'chime');
    $data = onlineInSessionGroupTherapySessionForJoin();
    app()->instance(VideoProviderInterface::class, fakeVideoProvider());
    $videoSession = VideoSession::factory()->create(['session_id' => $data['session']->id, 'provider' => 'chime']);
    VideoSessionParticipant::factory()->count((int) ConstantsEnum::groupTherapyVideoMaxParticipants->value - 1)->create([
        'video_session_id' => $videoSession->id,
        'left_at' => null,
    ]);
    VideoSessionParticipant::factory()->create([
        'video_session_id' => $videoSession->id, 'participant_type' => User::class,
        'participant_id' => $data['member']->id, 'left_at' => null,
    ]);

    JoinVideoSessionAction::new()->execute($data['session'], $data['member']);

    expect(fn () => JoinVideoSessionAction::new()->execute($data['session'], $data['creator']))
        ->toThrow(VideoException::class, 'This video call has reached its maximum number of participants.');
});

// --- SCRUM-334: masking is decided from the group as it is under the join lock -----------------

test('a join reads the group\'s anonymity fresh, not from an already-loaded copy', function () {
    $data = onlineInSessionGroupTherapySessionForJoin();
    $fakeProvider = fakeVideoProvider();
    app()->instance(VideoProviderInterface::class, $fakeProvider);
    $data['groupTherapy']->update(['anonymous' => false]);
    $session = $data['session']->fresh()->load('for');
    expect($session->for->anonymous)->toBeFalsy();

    // The group becomes anonymous after the join's request loaded it.
    GroupTherapy::query()->whereKey($data['groupTherapy']->id)->update(['anonymous' => true]);

    JoinVideoSessionAction::new()->execute($session, $data['creator']);

    [, , $displayName, , $canSendVideo] = $fakeProvider->createParticipantCredentialsCalls[0];
    expect($displayName)->toBe(ConstantsEnum::anonymousUserLabel->value)
        ->and($canSendVideo)->toBeFalse();
});

// --- SCRUM-378: a join and a removal retry for the same person never interleave ---------------

test('a join waits for the same person\'s removal retry, and says so if it can\'t', function () {
    Config::set('video.join_lock_wait_seconds', 0);
    $data = onlineInSessionGroupTherapySessionForJoin();
    app()->instance(VideoProviderInterface::class, fakeVideoProvider());
    $held = Cache::store(config('cache.scheduler_store'))->lock("video-join:{$data['session']->id}:{$data['member']->id}", 30);
    $held->get();

    expect(fn () => JoinVideoSessionAction::new()->execute($data['session'], $data['member']))
        ->toThrow(VideoException::class, 'Still joining you to the video call.');

    $held->release();
    JoinVideoSessionAction::new()->execute($data['session'], $data['member']);
    expect(VideoSessionParticipant::query()->where('participant_id', $data['member']->id)->whereNull('left_at')->count())->toBe(1);
});
