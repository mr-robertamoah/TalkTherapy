<?php

use App\Actions\GroupTherapy\LeaveGroupTherapyAction;
use App\Actions\Guardianship\RemoveGuardianFromWardAction;
use App\Actions\Video\EnsureVideoIsAvailableForSessionAction;
use App\Contracts\VideoProviderInterface;
use App\Enums\SessionStatusEnum;
use App\Enums\TherapyStatusEnum;
use App\Enums\VideoConsentLevelEnum;
use App\Enums\VideoConsentRevocationReasonEnum;
use App\Exceptions\VideoConsentRequiredException;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\Session;
use App\Models\User;
use App\Models\VideoConsent;
use App\Models\VideoSession;
use App\Models\VideoSessionParticipant;
use App\Notifications\GroupVideoConsentRequestedNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

// SCRUM-313 PR 2: a minor's video consent in a group -- they ask, their guardians answer from their own list of their
// ward's groups (for the whole group or one session), and the join gate lets them in on it.

function gvcMinor(): array
{
    $minor = User::factory()->create(['dob' => now()->subYears(15)->toDateString(), 'email_verified_at' => now()]);
    $guardian = User::factory()->adult()->create(['email_verified_at' => now()]);
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id, 'ward_was_minor_at_creation' => true]);

    return [$minor, $guardian];
}

// A running group (created by an adult) with an active counsellor, these members, and a live online session.
function gvcGroup(User ...$members): array
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

    return [$group, $session];
}

function gvcGrant($test, User $guardian, GroupTherapy $group, User $ward, string $scope = 'group')
{
    return $test->actingAs($guardian)->postJson(route('group.therapies.video_consent.grant', ['groupTherapyId' => $group->id]), ['wardId' => $ward->id, 'scope' => $scope]);
}

function gvcCanJoin(Session $session, User $user): bool
{
    try {
        EnsureVideoIsAvailableForSessionAction::new()->execute($session->fresh(), $user);

        return true;
    } catch (VideoConsentRequiredException) {
        return false;
    }
}

test('a minor member can\'t join a group\'s video until a guardian approves it for the group', function () {
    [$minor, $guardian] = gvcMinor();
    [$group, $session] = gvcGroup($minor);

    expect(gvcCanJoin($session, $minor))->toBeFalse();

    gvcGrant($this, $guardian, $group, $minor)->assertOk()
        ->assertJsonPath('data.wards.0.id', $minor->id)
        ->assertJsonPath('data.wards.0.groupConsent.scope', 'group');

    expect(gvcCanJoin($session, $minor))->toBeTrue()
        ->and(VideoConsent::query()->sole()->level)->toBe(VideoConsentLevelEnum::speak->value);
});

test('approving one session only covers that session', function () {
    [$minor, $guardian] = gvcMinor();
    [$group, $session] = gvcGroup($minor);

    gvcGrant($this, $guardian, $group, $minor, 'session')->assertOk()->assertJsonPath('data.wards.0.sessionConsent.scope', 'session');

    expect(gvcCanJoin($session, $minor))->toBeTrue();

    $session->update(['status' => SessionStatusEnum::held->value]);
    $next = Session::factory()->create([
        'for_type' => GroupTherapy::class, 'for_id' => $group->id, 'status' => SessionStatusEnum::in_session->value,
        'type' => 'ONLINE', 'start_time' => now()->subMinute(), 'end_time' => now()->addHour(),
    ]);

    expect(gvcCanJoin($next, $minor))->toBeFalse();
});

test('only an active guardian of a minor member of the group can approve, and only for that ward', function () {
    [$minor, $guardian] = gvcMinor();
    [$otherMinor, $otherGuardian] = gvcMinor();
    $adult = User::factory()->adult()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $adult->id, 'ward_was_minor_at_creation' => false]);
    [$group] = gvcGroup($minor, $adult);

    gvcGrant($this, $otherGuardian, $group, $minor)->assertStatus(422);
    gvcGrant($this, $otherGuardian, $group, $otherMinor)->assertStatus(422);
    gvcGrant($this, $guardian, $group, $adult)->assertStatus(422);
    gvcGrant($this, User::factory()->adult()->create(), $group, $minor)->assertStatus(422);
    $this->actingAs($otherGuardian)->getJson(route('group.therapies.video_consent.show', ['groupTherapyId' => $group->id]))->assertStatus(422);

    expect(VideoConsent::query()->count())->toBe(0);
});

test('a guardian sees only their own wards in the group, never another member', function () {
    [$minor, $guardian] = gvcMinor();
    [$otherMinor] = gvcMinor();
    [$group] = gvcGroup($minor, $otherMinor);

    $response = $this->actingAs($guardian)->getJson(route('group.therapies.video_consent.show', ['groupTherapyId' => $group->id]))->assertOk();

    expect(collect($response->json('data.wards'))->pluck('id')->all())->toBe([$minor->id])
        ->and($response->getContent())->not->toContain($otherMinor->name);
});

test('two minors\' consents in the same group are each their own', function () {
    [$minor, $guardian] = gvcMinor();
    [$otherMinor, $otherGuardian] = gvcMinor();
    [$group, $session] = gvcGroup($minor, $otherMinor);

    gvcGrant($this, $guardian, $group, $minor)->assertOk();
    gvcGrant($this, $guardian, $group, $minor)->assertOk();

    expect(gvcCanJoin($session, $minor))->toBeTrue()
        ->and(gvcCanJoin($session, $otherMinor))->toBeFalse()
        ->and(VideoConsent::query()->count())->toBe(1);

    gvcGrant($this, $otherGuardian, $group, $otherMinor)->assertOk();

    expect(gvcCanJoin($session, $otherMinor))->toBeTrue()
        ->and(VideoConsent::query()->count())->toBe(2);
});

test('a guardian can withdraw it -- only a consent for this group', function () {
    [$minor, $guardian] = gvcMinor();
    [$group, $session] = gvcGroup($minor);
    [$otherGroup] = gvcGroup($minor);
    gvcGrant($this, $guardian, $group, $minor)->assertOk();
    gvcGrant($this, $guardian, $otherGroup, $minor)->assertOk();
    $otherConsent = VideoConsent::query()->where('consentable_id', $otherGroup->id)->sole();
    $consent = VideoConsent::query()->where('consentable_id', $group->id)->sole();

    $this->actingAs($guardian)->deleteJson(route('group.therapies.video_consent.revoke', ['groupTherapyId' => $group->id, 'consentId' => $otherConsent->id]))->assertStatus(422);
    $this->actingAs($guardian)->deleteJson(route('group.therapies.video_consent.revoke', ['groupTherapyId' => $group->id, 'consentId' => $consent->id]))
        ->assertOk()->assertJsonPath('data.wards.0.groupConsent', null);

    expect($consent->fresh()->revoked_at)->not->toBeNull()
        ->and($otherConsent->fresh()->revoked_at)->toBeNull()
        ->and(gvcCanJoin($session, $minor))->toBeFalse();
});

test('a minor asks their guardians, once in a while', function () {
    Notification::fake();
    Cache::flush();
    [$minor, $guardian] = gvcMinor();
    [$group] = gvcGroup($minor);
    $ask = fn () => $this->actingAs($minor)->postJson(route('group.therapies.video_consent.request', ['groupTherapyId' => $group->id]));

    $ask()->assertOk()->assertJsonPath('sent', true);
    $ask()->assertOk()->assertJsonPath('sent', false);

    Notification::assertSentToTimes($guardian, GroupVideoConsentRequestedNotification::class, 1);
});

test('an adult member, or someone outside the group, can\'t ask', function () {
    Notification::fake();
    $adult = User::factory()->adult()->create();
    [$minor] = gvcMinor();
    [$group] = gvcGroup($adult);

    $this->actingAs($adult)->postJson(route('group.therapies.video_consent.request', ['groupTherapyId' => $group->id]))->assertStatus(422);
    $this->actingAs($minor)->postJson(route('group.therapies.video_consent.request', ['groupTherapyId' => $group->id]))->assertStatus(422);

    Notification::assertNothingSent();
});

test('leaving the group lapses its consent; coming back needs new consent', function () {
    [$minor, $guardian] = gvcMinor();
    [$group, $session] = gvcGroup($minor);
    gvcGrant($this, $guardian, $group, $minor)->assertOk();
    gvcGrant($this, $guardian, $group, $minor, 'session')->assertStatus(200);

    LeaveGroupTherapyAction::new()->execute($minor, $group);

    expect(VideoConsent::query()->whereNull('revoked_at')->count())->toBe(0)
        ->and(VideoConsent::query()->pluck('revocation_reason')->unique()->all())->toBe([VideoConsentRevocationReasonEnum::membership_ended->value]);
});

test('the group page tells a minor they need consent only while they have none for the live session', function () {
    [$minor, $guardian] = gvcMinor();
    [$group] = gvcGroup($minor);
    $flag = fn () => $this->actingAs($minor)->get(route('group.therapies.get', ['groupTherapyId' => $group->id]))
        ->assertOk()->inertiaProps('therapy.viewerNeedsGuardianVideoConsent');

    expect($flag())->toBeTrue();

    gvcGrant($this, $guardian, $group, $minor)->assertOk();

    expect($flag())->toBeFalse();
});

// A provider that records how each person was let in.
function gvcProvider(): object
{
    $provider = new class implements VideoProviderInterface
    {
        public array $joined = [];

        public array $removed = [];

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

        public function updateParticipantCapabilities(VideoSession $videoSession, User $user, bool $canSendAudio, bool $canSendVideo): void {}
    };
    app()->instance(VideoProviderInterface::class, $provider);

    return $provider;
}

test('joining without consent is refused as needing consent, so the call panel says why', function () {
    gvcProvider();
    [$minor] = gvcMinor();
    [, $session] = gvcGroup($minor);

    $this->actingAs($minor)->postJson(route('sessions.video.join', ['sessionId' => $session->id]))
        ->assertStatus(422)->assertJsonPath('videoConsentRequired', true);
});

test('a minor creator let in by consent watches and listens only, for now', function () {
    $provider = gvcProvider();
    [$minor, $guardian] = gvcMinor();
    [$group, $session] = gvcGroup();
    $group->forceFill(['addedby_id' => $minor->id])->save();
    gvcGrant($this, $guardian, $group->fresh(), $minor)->assertOk();

    $this->actingAs($minor)->postJson(route('sessions.video.join', ['sessionId' => $session->id]))->assertOk();

    expect($provider->joined[$minor->id])->toBeTrue();
});

test('a grant from a guardian the minor no longer has doesn\'t count, or stop another guardian granting', function () {
    [$minor, $guardian] = gvcMinor();
    $second = User::factory()->adult()->create();
    Guardianship::query()->create(['guardian_id' => $second->id, 'ward_id' => $minor->id, 'ward_was_minor_at_creation' => true]);
    [$group, $session] = gvcGroup($minor);
    gvcGrant($this, $guardian, $group, $minor)->assertOk();
    $guardian->update(['dob' => null]);

    expect(gvcCanJoin($session, $minor))->toBeFalse();
    $this->actingAs($second)->getJson(route('group.therapies.video_consent.show', ['groupTherapyId' => $group->id]))
        ->assertOk()->assertJsonPath('data.wards.0.groupConsent', null);

    gvcGrant($this, $second, $group, $minor)->assertOk();

    expect(gvcCanJoin($session, $minor))->toBeTrue();
});

test('a minor on a suspended guardian\'s consent alone is taken out of the call; another guardian\'s keeps them in', function () {
    $provider = gvcProvider();
    [$minor, $guardian] = gvcMinor();
    [$group, $session] = gvcGroup($minor);
    $room = VideoSession::query()->create(['session_id' => $session->id, 'provider' => 'daily', 'started_at' => now()]);
    VideoSessionParticipant::query()->create(['video_session_id' => $room->id, 'participant_type' => User::class, 'participant_id' => $minor->id, 'joined_at' => now()]);
    gvcGrant($this, $guardian, $group, $minor)->assertOk();
    $guardian->update(['dob' => null]);

    RemoveGuardianFromWardAction::new()->execute($guardian, $minor, RemoveGuardianFromWardAction::GUARDIAN_SUSPENDED);

    expect($provider->removed)->toBe([$minor->id]);
});

test('leaving lapses only that group\'s consent, and coming back needs new consent', function () {
    [$minor, $guardian] = gvcMinor();
    [$group, $session] = gvcGroup($minor);
    [$otherGroup] = gvcGroup($minor);
    gvcGrant($this, $guardian, $group, $minor)->assertOk();
    gvcGrant($this, $guardian, $otherGroup, $minor)->assertOk();
    $therapyGrant = VideoConsent::factory()->create(['ward_id' => $minor->id, 'guardian_id' => $guardian->id]);

    LeaveGroupTherapyAction::new()->execute($minor, $group);
    $group->allMemberships()->updateExistingPivot($minor->id, ['left_at' => null]);

    expect(gvcCanJoin($session, $minor))->toBeFalse()
        ->and(VideoConsent::query()->where('consentable_id', $otherGroup->id)->where('consentable_type', GroupTherapy::class)->sole()->revoked_at)->toBeNull()
        ->and($therapyGrant->fresh()->revoked_at)->toBeNull();
});

test('a session-only consent can be withdrawn; another ward\'s guardian can\'t, and learns nothing', function () {
    [$minor, $guardian] = gvcMinor();
    [$otherMinor, $otherGuardian] = gvcMinor();
    [$group, $session] = gvcGroup($minor, $otherMinor);
    gvcGrant($this, $guardian, $group, $minor, 'session')->assertOk();
    $consent = VideoConsent::query()->sole();
    $revoke = fn (User $as, $id) => $this->actingAs($as)->deleteJson(route('group.therapies.video_consent.revoke', ['groupTherapyId' => $group->id, 'consentId' => $id]));

    $other = $revoke($otherGuardian, $consent->id)->assertStatus(422)->json('message');
    expect($revoke($otherGuardian, 999999)->assertStatus(422)->json('message'))->toBe($other);
    $revoke(User::factory()->adult()->create(), $consent->id)->assertStatus(422);
    expect($consent->fresh()->revoked_at)->toBeNull();

    $revoke($guardian, $consent->id)->assertOk()->assertJsonPath('data.wards.0.sessionConsent', null);
    expect(gvcCanJoin($session, $minor))->toBeFalse();
});

test('"this session only" needs a session to come', function () {
    [$minor, $guardian] = gvcMinor();
    [$group, $session] = gvcGroup($minor);
    $session->update(['status' => SessionStatusEnum::held->value]);

    gvcGrant($this, $guardian, $group, $minor, 'session')->assertStatus(422);
});

test('a minor with no guardian to ask is told so', function () {
    Notification::fake();
    $minor = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);
    [$group] = gvcGroup($minor);

    $this->actingAs($minor)->postJson(route('group.therapies.video_consent.request', ['groupTherapyId' => $group->id]))->assertStatus(422);
});
