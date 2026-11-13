<?php

use App\Actions\VideoConsent\HasValidVideoConsentForSessionAction;
use App\Actions\VideoConsent\InvalidateVideoConsentAction;
use App\Actions\VideoConsent\IsVideoConsentOutstandingForSessionAction;
use App\Actions\VideoConsent\SendGuardianVideoConsentRemindersAction;
use App\Contracts\VideoProviderInterface;
use App\Enums\SessionStatusEnum;
use App\Enums\TherapyStatusEnum;
use App\Enums\VideoConsentLevelEnum;
use App\Enums\VideoConsentReminderWindowEnum;
use App\Enums\VideoConsentRevocationReasonEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoConsent;
use App\Models\VideoConsentReminder;
use App\Models\VideoSession;
use App\Models\VideoSessionParticipant;
use Illuminate\Support\Facades\Notification;

// SCRUM-313 PR 1: video consent per ward -- a group can have several minors, each with their own guardians' grants
// on the same group or session -- and withdrawing a group grant takes only that minor out of the call.

function wscMinor(): array
{
    $minor = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);
    $guardian = User::factory()->adult()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id, 'ward_was_minor_at_creation' => true]);

    return [$minor, $guardian];
}

function wscGrant(User $ward, User $guardian, $consentable, array $attributes = []): VideoConsent
{
    return VideoConsent::factory()->create(array_merge([
        'ward_id' => $ward->id, 'guardian_id' => $guardian->id,
        'consentable_type' => $consentable::class, 'consentable_id' => $consentable->id,
    ], $attributes));
}

// A running group with an active counsellor and the given members, and a live session.
function wscGroup(User ...$members): array
{
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id, 'verified_at' => now()]);
    $group = GroupTherapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => User::factory()->adult()->create()->id, 'status' => TherapyStatusEnum::in_session->value,
    ]);
    $group->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    foreach ($members as $member) {
        $group->allMemberships()->attach($member->id, ['anonymous' => false, 'was_minor_at_join' => ! $member->isAdult()]);
    }
    $session = Session::factory()->create([
        'for_type' => GroupTherapy::class, 'for_id' => $group->id, 'status' => SessionStatusEnum::in_session->value,
        'type' => 'ONLINE', 'start_time' => now()->subMinutes(5), 'end_time' => now()->addHour(),
    ]);

    return [$group, $session, $counsellor];
}

function wscInCall(Session $session, User ...$people): VideoSession
{
    $room = VideoSession::query()->create(['session_id' => $session->id, 'provider' => 'daily', 'started_at' => now()]);
    foreach ($people as $person) {
        VideoSessionParticipant::query()->create(['video_session_id' => $room->id, 'participant_type' => User::class, 'participant_id' => $person->id, 'joined_at' => now()]);
    }

    return $room;
}

function wscFakeProvider(): object
{
    $provider = new class implements VideoProviderInterface
    {
        public array $removed = [];

        public bool $ended = false;

        public function createRoom(VideoSession $videoSession): array
        {
            return ['room_id' => 'room', 'meta' => []];
        }

        public function createParticipantCredentials(VideoSession $videoSession, User $user, string $displayName, bool $receiveOnly = false, bool $canSendVideo = true): array
        {
            return [];
        }

        public function endRoom(VideoSession $videoSession): void
        {
            $this->ended = true;
        }

        public function removeParticipant(VideoSession $videoSession, User $user): void
        {
            $this->removed[] = $user->id;
        }

        public function updateParticipantCapabilities(VideoSession $videoSession, User $user, bool $canSendAudio, bool $canSendVideo): void {}
    };
    app()->instance(VideoProviderInterface::class, $provider);

    return $provider;
}

test('a grant is speak-level unless it says otherwise', function () {
    [$minor, $guardian] = wscMinor();
    $grant = wscGrant($minor, $guardian, Therapy::factory()->create())->fresh();

    expect($grant->level)->toBe(VideoConsentLevelEnum::speak->value)
        ->and($grant->allowsSpeaking())->toBeTrue()
        ->and(wscGrant($minor, $guardian, Therapy::factory()->create(), ['level' => VideoConsentLevelEnum::watch->value])->allowsSpeaking())->toBeFalse();
});

test('another ward\'s grant on the same therapy never counts as this client\'s', function () {
    [$minor, $guardian] = wscMinor();
    [$other, $otherGuardian] = wscMinor();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $minor->id]);
    $session = Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id]);
    wscGrant($other, $otherGuardian, $therapy);

    expect(HasValidVideoConsentForSessionAction::new()->execute($session))->toBeFalse()
        ->and(IsVideoConsentOutstandingForSessionAction::new()->execute($session))->toBeTrue();

    wscGrant($minor, $guardian, $therapy);

    expect(HasValidVideoConsentForSessionAction::new()->execute($session))->toBeTrue()
        ->and(IsVideoConsentOutstandingForSessionAction::new()->execute($session))->toBeFalse();
});

test('a group member\'s consent is their own: the group or the session, from a guardian they still have', function () {
    [$minor, $guardian] = wscMinor();
    [$other, $otherGuardian] = wscMinor();
    [$group, $session] = wscGroup($minor, $other);
    $check = fn () => HasValidVideoConsentForSessionAction::new()->forGroupMember($session, $minor);

    wscGrant($other, $otherGuardian, $group);
    expect($check())->toBeFalse();

    $groupWide = wscGrant($minor, $guardian, $group, ['level' => VideoConsentLevelEnum::watch->value]);
    expect($check())->toBeTrue();

    $groupWide->update(['revoked_at' => now()]);
    expect($check())->toBeFalse();

    wscGrant($minor, $guardian, $session);
    expect($check())->toBeTrue();

    Guardianship::query()->where('ward_id', $minor->id)->delete();
    expect($check())->toBeFalse();
});

test('withdrawing a group grant takes only that minor out of the call -- the call goes on for everyone else', function () {
    $provider = wscFakeProvider();
    [$minor, $guardian] = wscMinor();
    $adult = User::factory()->adult()->create();
    [$group, $session, $counsellor] = wscGroup($minor, $adult);
    $room = wscInCall($session, $minor, $adult, $counsellor->user);
    $grant = wscGrant($minor, $guardian, $group);

    InvalidateVideoConsentAction::new()->execute($grant, VideoConsentRevocationReasonEnum::guardian_action, $guardian);

    expect($grant->fresh()->revoked_at)->not->toBeNull()
        ->and($provider->ended)->toBeFalse()
        ->and($provider->removed)->toBe([$minor->id])
        ->and($room->participantRowsFor($minor->id)->whereNull('left_at')->exists())->toBeFalse()
        ->and($room->participantRowsFor($adult->id)->whereNull('left_at')->exists())->toBeTrue()
        ->and($session->fresh()->status)->toBe(SessionStatusEnum::in_session->value);
});

test('withdrawing one session\'s grant leaves a minor whose group-wide grant still covers them in the call', function () {
    $provider = wscFakeProvider();
    [$minor, $guardian] = wscMinor();
    [$group, $session] = wscGroup($minor);
    wscInCall($session, $minor);
    wscGrant($minor, $guardian, $group);
    $sessionGrant = wscGrant($minor, $guardian, $session);

    InvalidateVideoConsentAction::new()->execute($sessionGrant, VideoConsentRevocationReasonEnum::guardian_action, $guardian);

    expect($provider->removed)->toBe([]);
});

test('withdrawing a one-to-one grant still ends the call, as before', function () {
    $provider = wscFakeProvider();
    [$minor, $guardian] = wscMinor();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $minor->id]);
    $session = Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id, 'status' => SessionStatusEnum::in_session->value]);
    wscInCall($session, $minor);
    $grant = wscGrant($minor, $guardian, $therapy);

    InvalidateVideoConsentAction::new()->execute($grant, VideoConsentRevocationReasonEnum::guardian_action, $guardian);

    expect($provider->ended)->toBeTrue();
});

test('a reminder is recorded for the ward it was sent about', function () {
    Notification::fake();
    [$minor] = wscMinor();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $minor->id, 'counsellor_id' => $counsellor->id]);
    $session = Session::factory()->create([
        'for_type' => Therapy::class, 'for_id' => $therapy->id, 'status' => SessionStatusEnum::pending->value,
        'type' => 'ONLINE', 'start_time' => now()->addMinutes(30), 'end_time' => now()->addMinutes(90),
    ]);

    SendGuardianVideoConsentRemindersAction::new()->execute(VideoConsentReminderWindowEnum::hour_before);

    expect(VideoConsentReminder::query()->where('session_id', $session->id)->pluck('ward_id')->all())->toBe([$minor->id]);
});

test('a member who has turned 18 is not taken out when a grant from when they were a minor lapses', function () {
    $provider = wscFakeProvider();
    [$minor, $guardian] = wscMinor();
    [$group, $session] = wscGroup($minor);
    wscInCall($session, $minor);
    $grant = wscGrant($minor, $guardian, $group);
    $minor->update(['dob' => now()->subYears(18)->subDay()->toDateString()]);

    InvalidateVideoConsentAction::new()->execute($grant, VideoConsentRevocationReasonEnum::guardian_action, $guardian);

    expect($provider->removed)->toBe([]);
});

test('another guardian\'s grant still covering the minor keeps them in the call', function () {
    $provider = wscFakeProvider();
    [$minor, $guardian] = wscMinor();
    $secondGuardian = User::factory()->adult()->create();
    Guardianship::query()->create(['guardian_id' => $secondGuardian->id, 'ward_id' => $minor->id, 'ward_was_minor_at_creation' => true]);
    [$group, $session] = wscGroup($minor);
    wscInCall($session, $minor);
    $first = wscGrant($minor, $guardian, $group);
    wscGrant($minor, $secondGuardian, $session);

    InvalidateVideoConsentAction::new()->execute($first, VideoConsentRevocationReasonEnum::guardian_action, $guardian);

    expect($provider->removed)->toBe([]);
});

test('a group withdrawal leaves the minor\'s one-to-one call alone', function () {
    $provider = wscFakeProvider();
    [$minor, $guardian] = wscMinor();
    [$group, $session] = wscGroup($minor);
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $minor->id]);
    $oneToOne = Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id, 'status' => SessionStatusEnum::in_session->value]);
    wscInCall($session, $minor);
    $oneToOneRoom = wscInCall($oneToOne, $minor);
    $grant = wscGrant($minor, $guardian, $group);

    InvalidateVideoConsentAction::new()->execute($grant, VideoConsentRevocationReasonEnum::guardian_action, $guardian);

    expect($provider->removed)->toBe([$minor->id])
        ->and($provider->ended)->toBeFalse()
        ->and($oneToOneRoom->participantRowsFor($minor->id)->whereNull('left_at')->exists())->toBeTrue();
});

test('a minor is still taken out when the group session was soft-deleted while its call went on', function () {
    $provider = wscFakeProvider();
    [$minor, $guardian] = wscMinor();
    [$group, $session] = wscGroup($minor);
    wscInCall($session, $minor);
    $grant = wscGrant($minor, $guardian, $session);
    $session->delete();

    InvalidateVideoConsentAction::new()->execute($grant, VideoConsentRevocationReasonEnum::guardian_action, $guardian);

    expect($provider->removed)->toBe([$minor->id]);
});

test('a group session never passes the one-to-one consent check', function () {
    [$minor] = wscMinor();
    [, $session] = wscGroup($minor);

    expect(HasValidVideoConsentForSessionAction::new()->execute($session))->toBeFalse();
});

test('a grant without a level is refused, never saved as the most it could allow', function () {
    [$minor, $guardian] = wscMinor();
    $therapy = Therapy::factory()->create();

    expect(fn () => VideoConsent::query()->create([
        'ward_id' => $minor->id, 'guardian_id' => $guardian->id, 'consentable_type' => Therapy::class,
        'consentable_id' => $therapy->id, 'granted_at' => now(),
    ]))->toThrow(LogicException::class);
});
