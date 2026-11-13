<?php

use App\Actions\Guardianship\RemoveGuardianFromWardAction;
use App\Actions\Video\EnsureMemberMayBeGrantedSpeakingAction;
use App\Actions\Video\GrantSpeakingPermissionAction;
use App\Actions\Video\RaiseHandAction;
use App\Actions\VideoConsent\HasValidVideoConsentForSessionAction;
use App\Contracts\VideoProviderInterface;
use App\Enums\SessionStatusEnum;
use App\Enums\SpeakingPermissionRevocationReasonEnum;
use App\Enums\TherapyStatusEnum;
use App\Enums\VideoConsentRevocationReasonEnum;
use App\Events\SpeakingPermissionChangedEvent;
use App\Exceptions\VideoException;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\Session;
use App\Models\User;
use App\Models\VideoConsent;
use App\Models\VideoSession;
use App\Models\VideoSessionParticipant;
use App\Models\VideoSessionSpeakingGrant;
use Illuminate\Support\Facades\Event;

// SCRUM-329: an under-18 in a group may speak only with their guardians' consent to speak (not just to watch), and
// never while anonymous there; going down to watch only takes the floor away at once.

function mscMinor(): array
{
    $minor = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);
    $guardian = User::factory()->adult()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id, 'ward_was_minor_at_creation' => true]);

    return [$minor, $guardian];
}

// A running, non-anonymous group with an active counsellor, created by $creator (an adult by default), with $members
// (non-anonymous unless listed in $anonymous) and a live online session.
function mscGroup(?User $creator = null, array $members = [], array $anonymous = []): array
{
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id, 'verified_at' => now()]);
    $group = GroupTherapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => ($creator ?? User::factory()->adult()->create())->id,
        'status' => TherapyStatusEnum::in_session->value, 'anonymous' => false,
    ]);
    $group->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    foreach ($members as $member) {
        $group->allMemberships()->attach($member->id, ['anonymous' => in_array($member, $anonymous, true), 'was_minor_at_join' => ! $member->isAdult()]);
    }
    $session = Session::factory()->create([
        'for_type' => GroupTherapy::class, 'for_id' => $group->id, 'status' => SessionStatusEnum::in_session->value,
        'type' => 'ONLINE', 'start_time' => now()->subMinutes(5), 'end_time' => now()->addHour(),
    ]);

    return [$group, $session, $counsellor];
}

function mscProvider(bool $failsToMute = false): object
{
    $provider = new class($failsToMute) implements VideoProviderInterface
    {
        public array $joined = [];

        public array $capabilities = [];

        public array $removed = [];

        public function __construct(private bool $failsToMute) {}

        public function createRoom(VideoSession $videoSession): array
        {
            return ['room_id' => 'room', 'meta' => []];
        }

        public function createParticipantCredentials(VideoSession $videoSession, User $user, string $displayName, bool $receiveOnly = false, bool $canSendVideo = true): array
        {
            $this->joined[$user->id] = $receiveOnly;

            return ['token' => 't', 'room_url' => 'https://example.test/room'];
        }

        public function endRoom(VideoSession $videoSession): void {}

        public function removeParticipant(VideoSession $videoSession, User $user): void
        {
            $this->removed[] = $user->id;
        }

        public function updateParticipantCapabilities(VideoSession $videoSession, User $user, bool $canSendAudio, bool $canSendVideo): void
        {
            if ($this->failsToMute && ! $canSendAudio) {
                throw new RuntimeException('provider down');
            }

            $this->capabilities[] = [$user->id, $canSendAudio, $canSendVideo];
        }
    };
    app()->instance(VideoProviderInterface::class, $provider);

    return $provider;
}

function mscGrant($test, User $guardian, GroupTherapy $group, User $ward, string $level, string $scope = 'group')
{
    return $test->actingAs($guardian)->postJson(route('group.therapies.video_consent.grant', ['groupTherapyId' => $group->id]), ['wardId' => $ward->id, 'scope' => $scope, 'level' => $level]);
}

function mscInCall(Session $session, User $person): VideoSessionParticipant
{
    $room = VideoSession::query()->firstOrCreate(['session_id' => $session->id, 'ended_at' => null], ['provider' => 'daily', 'started_at' => now()]);

    return VideoSessionParticipant::query()->create(['video_session_id' => $room->id, 'participant_type' => User::class, 'participant_id' => $person->id, 'joined_at' => now()]);
}

test('a consent that doesn\'t say what it allows is watch and listen only', function () {
    [$minor, $guardian] = mscMinor();
    [$group] = mscGroup(members: [$minor]);

    $this->actingAs($guardian)->postJson(route('group.therapies.video_consent.grant', ['groupTherapyId' => $group->id]), ['wardId' => $minor->id, 'scope' => 'group'])
        ->assertOk()->assertJsonPath('data.wards.0.groupConsent.level', 'WATCH');
    $this->actingAs($guardian)->postJson(route('group.therapies.video_consent.grant', ['groupTherapyId' => $group->id]), ['wardId' => $minor->id, 'scope' => 'session', 'level' => 'LOUD'])
        ->assertStatus(422);
});

test('a minor member with consent to watch only can\'t raise a hand; with consent to speak they can', function () {
    Event::fake();
    mscProvider();
    [$minor, $guardian] = mscMinor();
    [$group, $session] = mscGroup(members: [$minor]);
    mscInCall($session, $minor);

    mscGrant($this, $guardian, $group, $minor, 'WATCH')->assertOk();
    expect(fn () => RaiseHandAction::new()->execute($session->fresh(), $minor))
        ->toThrow(VideoException::class, EnsureMemberMayBeGrantedSpeakingAction::NEEDS_SPEAK_CONSENT);

    $consent = VideoConsent::query()->sole();
    $this->actingAs($guardian)->patchJson(route('group.therapies.video_consent.level', ['groupTherapyId' => $group->id, 'consentId' => $consent->id]), ['level' => 'SPEAK'])
        ->assertOk()->assertJsonPath('data.wards.0.groupConsent.level', 'SPEAK');

    RaiseHandAction::new()->execute($session->fresh(), $minor);

    expect(VideoConsent::query()->count())->toBe(2)
        ->and($consent->fresh()->revoked_at)->not->toBeNull();
});

test('an anonymous minor only ever watches, whatever the consent says', function () {
    mscProvider();
    [$minor, $guardian] = mscMinor();
    [$group, $session, $counsellor] = mscGroup(members: [$minor], anonymous: [$minor]);
    mscInCall($session, $minor);
    mscGrant($this, $guardian, $group, $minor, 'SPEAK')->assertOk();

    expect(fn () => GrantSpeakingPermissionAction::new()->execute($session->fresh(), $counsellor->user, $minor->id))
        ->toThrow(VideoException::class, EnsureMemberMayBeGrantedSpeakingAction::ANONYMOUS_MINOR);
});

test('a minor creator joins to watch with consent to watch, and takes part with consent to speak', function () {
    $provider = mscProvider();
    [$minor, $guardian] = mscMinor();
    [$group, $session] = mscGroup(creator: $minor);

    mscGrant($this, $guardian, $group, $minor, 'WATCH')->assertOk();
    $this->actingAs($minor)->postJson(route('sessions.video.join', ['sessionId' => $session->id]))->assertOk()
        ->assertJsonPath('participant.receiveOnly', true)
        ->assertJsonPath('participant.speakingNote', EnsureMemberMayBeGrantedSpeakingAction::NEEDS_SPEAK_CONSENT);
    expect($provider->joined[$minor->id])->toBeTrue();

    $this->actingAs($minor)->postJson(route('sessions.video.leave', ['sessionId' => $session->id]));
    VideoConsent::query()->update(['level' => 'SPEAK']);

    $this->actingAs($minor)->postJson(route('sessions.video.join', ['sessionId' => $session->id]))->assertOk()
        ->assertJsonPath('participant.receiveOnly', false);
    expect($provider->joined[$minor->id])->toBeFalse();
});

test('going down to watch only takes the floor away straight away', function () {
    Event::fake();
    $provider = mscProvider();
    [$minor, $guardian] = mscMinor();
    [$group, $session, $counsellor] = mscGroup(members: [$minor]);
    $participant = mscInCall($session, $minor);
    mscGrant($this, $guardian, $group, $minor, 'SPEAK')->assertOk();
    GrantSpeakingPermissionAction::new()->execute($session->fresh(), $counsellor->user, $minor->id);

    $this->actingAs($guardian)->patchJson(route('group.therapies.video_consent.level', ['groupTherapyId' => $group->id, 'consentId' => VideoConsent::query()->sole()->id]), ['level' => 'WATCH'])
        ->assertOk();

    $grant = VideoSessionSpeakingGrant::query()->where('video_session_participant_id', $participant->id)->sole();
    expect($grant->revocation_reason)->toBe(SpeakingPermissionRevocationReasonEnum::guardian_consent->value)
        ->and(end($provider->capabilities))->toBe([$minor->id, false, false])
        ->and($provider->removed)->toBe([]);
});

test('if the provider can\'t take the floor away, the minor is taken out of the call instead', function () {
    Event::fake();
    $provider = mscProvider();
    [$minor, $guardian] = mscMinor();
    [$group, $session] = mscGroup(creator: $minor);
    mscGrant($this, $guardian, $group, $minor, 'SPEAK')->assertOk();
    $this->actingAs($minor)->postJson(route('sessions.video.join', ['sessionId' => $session->id]))->assertOk();
    $failing = mscProvider(failsToMute: true);

    $this->actingAs($guardian)->patchJson(route('group.therapies.video_consent.level', ['groupTherapyId' => $group->id, 'consentId' => VideoConsent::query()->sole()->id]), ['level' => 'WATCH'])
        ->assertOk();

    expect($failing->removed)->toBe([$minor->id]);
});

test('withdrawing consent to speak for a session leaves a minor whose group consent lets them watch in the call, without the floor', function () {
    Event::fake();
    $provider = mscProvider();
    [$minor, $guardian] = mscMinor();
    [$group, $session, $counsellor] = mscGroup(members: [$minor]);
    mscInCall($session, $minor);
    mscGrant($this, $guardian, $group, $minor, 'WATCH')->assertOk();
    mscGrant($this, $guardian, $group, $minor, 'SPEAK', 'session')->assertOk();
    GrantSpeakingPermissionAction::new()->execute($session->fresh(), $counsellor->user, $minor->id);
    $sessionConsent = VideoConsent::query()->where('consentable_type', Session::class)->sole();

    $this->actingAs($guardian)->deleteJson(route('group.therapies.video_consent.revoke', ['groupTherapyId' => $group->id, 'consentId' => $sessionConsent->id]))->assertOk();

    expect($provider->removed)->toBe([])
        ->and(end($provider->capabilities))->toBe([$minor->id, false, false]);
});

test('another ward\'s guardian can\'t change a consent\'s level', function () {
    [$minor, $guardian] = mscMinor();
    [$other, $otherGuardian] = mscMinor();
    [$group] = mscGroup(members: [$minor, $other]);
    mscGrant($this, $guardian, $group, $minor, 'WATCH')->assertOk();
    $consent = VideoConsent::query()->sole();

    $this->actingAs($otherGuardian)->patchJson(route('group.therapies.video_consent.level', ['groupTherapyId' => $group->id, 'consentId' => $consent->id]), ['level' => 'SPEAK'])
        ->assertStatus(422);

    expect($consent->fresh()->level)->toBe('WATCH')->and($consent->fresh()->revoked_at)->toBeNull();
});

test('the floor taken away is told to the call once, as a plain revoke -- never that a guardian decided it', function () {
    Event::fake();
    mscProvider();
    [$minor, $guardian] = mscMinor();
    [$group, $session, $counsellor] = mscGroup(members: [$minor]);
    mscInCall($session, $minor);
    mscGrant($this, $guardian, $group, $minor, 'SPEAK')->assertOk();
    GrantSpeakingPermissionAction::new()->execute($session->fresh(), $counsellor->user, $minor->id);

    $this->actingAs($guardian)->patchJson(route('group.therapies.video_consent.level', ['groupTherapyId' => $group->id, 'consentId' => VideoConsent::query()->sole()->id]), ['level' => 'WATCH'])->assertOk();

    Event::assertDispatchedTimes(SpeakingPermissionChangedEvent::class, 2);
    Event::assertDispatched(SpeakingPermissionChangedEvent::class, fn ($event) => ($event->broadcastWith()['reason'] ?? null) === 'MANUAL');
    Event::assertNotDispatched(SpeakingPermissionChangedEvent::class, fn ($event) => str_contains(json_encode($event->broadcastWith()), 'GUARDIAN_CONSENT'));
});

test('a member whose floor the provider can\'t take away is taken out of the call', function () {
    Event::fake();
    mscProvider();
    [$minor, $guardian] = mscMinor();
    [$group, $session, $counsellor] = mscGroup(members: [$minor]);
    $participant = mscInCall($session, $minor);
    mscGrant($this, $guardian, $group, $minor, 'SPEAK')->assertOk();
    GrantSpeakingPermissionAction::new()->execute($session->fresh(), $counsellor->user, $minor->id);
    $failing = mscProvider(failsToMute: true);

    $this->actingAs($guardian)->patchJson(route('group.therapies.video_consent.level', ['groupTherapyId' => $group->id, 'consentId' => VideoConsent::query()->sole()->id]), ['level' => 'WATCH'])->assertOk();

    expect($failing->removed)->toBe([$minor->id])
        ->and(VideoSessionSpeakingGrant::query()->where('video_session_participant_id', $participant->id)->whereNull('revoked_at')->exists())->toBeFalse();
});

test('a consenting guardian\'s suspension takes the floor away when another guardian\'s consent lets them watch', function () {
    Event::fake();
    $provider = mscProvider();
    [$minor, $guardian] = mscMinor();
    $second = User::factory()->adult()->create();
    Guardianship::query()->create(['guardian_id' => $second->id, 'ward_id' => $minor->id, 'ward_was_minor_at_creation' => true]);
    [$group, $session, $counsellor] = mscGroup(members: [$minor]);
    mscInCall($session, $minor);
    mscGrant($this, $guardian, $group, $minor, 'SPEAK', 'session')->assertOk();
    mscGrant($this, $second, $group, $minor, 'WATCH')->assertOk();
    GrantSpeakingPermissionAction::new()->execute($session->fresh(), $counsellor->user, $minor->id);
    $guardian->update(['dob' => null]);

    RemoveGuardianFromWardAction::new()->execute($guardian, $minor, RemoveGuardianFromWardAction::GUARDIAN_SUSPENDED);

    expect($provider->removed)->toBe([])
        ->and(end($provider->capabilities))->toBe([$minor->id, false, false]);
});

test('changing a level to what it is, or a withdrawn consent, makes nothing new', function () {
    [$minor, $guardian] = mscMinor();
    [$group] = mscGroup(members: [$minor]);
    mscGrant($this, $guardian, $group, $minor, 'WATCH')->assertOk();
    $consent = VideoConsent::query()->sole();
    $level = fn ($id, $level) => $this->actingAs($guardian)->patchJson(route('group.therapies.video_consent.level', ['groupTherapyId' => $group->id, 'consentId' => $id]), ['level' => $level]);

    $level($consent->id, 'WATCH')->assertOk();
    $level($consent->id, 'LOUD')->assertStatus(422);
    expect(VideoConsent::query()->count())->toBe(1);

    $level($consent->id, 'SPEAK')->assertOk();
    $level($consent->id, 'WATCH')->assertStatus(422);
    expect($consent->fresh()->revocation_reason)->toBe(VideoConsentRevocationReasonEnum::superseded->value)
        ->and(VideoConsent::query()->whereNull('revoked_at')->sole()->level)->toBe('SPEAK');
});

test('a fresh grant replaces one from a guardian who no longer counts, rather than sitting beside it', function () {
    [$minor, $guardian] = mscMinor();
    $second = User::factory()->adult()->create();
    Guardianship::query()->create(['guardian_id' => $second->id, 'ward_id' => $minor->id, 'ward_was_minor_at_creation' => true]);
    [$group, $session] = mscGroup(members: [$minor]);
    mscGrant($this, $guardian, $group, $minor, 'SPEAK')->assertOk();
    $guardian->update(['dob' => null]);

    mscGrant($this, $second, $group, $minor, 'WATCH')->assertOk();
    $guardian->update(['dob' => now()->subYears(40)->toDateString()]);

    expect(VideoConsent::query()->whereNull('revoked_at')->sole()->level)->toBe('WATCH')
        ->and(HasValidVideoConsentForSessionAction::new()->speakingAllowedFor($session->fresh(), $minor))->toBeFalse();
});

test('an anonymous minor is told in the call why they only watch', function () {
    mscProvider();
    [$minor, $guardian] = mscMinor();
    [$group, $session] = mscGroup(members: [$minor], anonymous: [$minor]);
    mscGrant($this, $guardian, $group, $minor, 'SPEAK')->assertOk();

    $this->actingAs($minor)->postJson(route('sessions.video.join', ['sessionId' => $session->id]))->assertOk()
        ->assertJsonPath('participant.canRaiseHand', false)
        ->assertJsonPath('participant.speakingNote', EnsureMemberMayBeGrantedSpeakingAction::ANONYMOUS_MINOR);
});
