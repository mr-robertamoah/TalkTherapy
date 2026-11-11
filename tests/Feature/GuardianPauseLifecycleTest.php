<?php

use App\Actions\Guardianship\HandleGuardianLossAction;
use App\Actions\Guardianship\RemoveGuardianFromWardAction;
use App\Actions\Link\PerformGuardianshipLinkAction;
use App\Actions\Link\PerformTherapyCounsellorLinkAction;
use App\Actions\Request\RespondToGuardianshipRequestAction;
use App\Actions\Suspension\ApplySuspensionAction;
use App\Actions\Suspension\LiftSuspensionAction;
use App\Actions\User\DeleteGuardianshipAction;
use App\Actions\Video\RemovePersonFromLiveCallsAction;
use App\Actions\VideoConsent\HasValidVideoConsentForSessionAction;
use App\Contracts\VideoProviderInterface;
use App\DTOs\CreateLinkDTO;
use App\DTOs\GetGuardianshipDTO;
use App\DTOs\RequestResponseDTO;
use App\DTOs\UpdateUserDTO;
use App\Enums\ConstantsEnum;
use App\Enums\LinkStateEnum;
use App\Enums\LinkTypeEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\SessionStatusEnum;
use App\Enums\SuspensionScopeEnum;
use App\Enums\TherapyStatusEnum;
use App\Events\GuardianAccessChangedEvent;
use App\Events\TherapyAccessChangedEvent;
use App\Exceptions\GuardianRequiredException;
use App\Exceptions\UserException;
use App\Http\Resources\GroupTherapyResource;
use App\Http\Resources\TherapyResource;
use App\Jobs\RemovePersonFromLiveCallJob;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\Link;
use App\Models\Message;
use App\Models\Report;
use App\Models\Request;
use App\Models\Session;
use App\Models\Suspension;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoConsent;
use App\Models\VideoSession;
use App\Models\VideoSessionParticipant;
use App\Notifications\ClientAccessPausedNotification;
use App\Notifications\ClientAccessResumedNotification;
use App\Notifications\GuardianAccessPausedNotification;
use App\Notifications\GuardianAccessResumedNotification;
use App\Services\SuspensionService;
use App\Services\UserService;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// SCRUM-288: how a minor's therapies are paused when their last active guardian goes (each removal
// path, exactly once), resumed when one comes back, and what stays allowed. Helpers are file-local
// and gpl-prefixed (--parallel).

function gplVideoProvider(): VideoProviderInterface
{
    return new class implements VideoProviderInterface
    {
        public array $ended = [];

        public array $removed = [];

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
            $this->ended[] = $videoSession->id;
        }

        public function removeParticipant(VideoSession $videoSession, User $user): void
        {
            $this->removed[] = [$videoSession->id, $user->id];
        }

        public function updateParticipantCapabilities(VideoSession $videoSession, User $user, bool $canSendAudio, bool $canSendVideo): void {}
    };
}

// A minor with one guardian, a 1:1 with a counsellor (live session) and a group with an adult member.
function gplFamily(array $therapyAttributes = []): array
{
    $minor = User::factory()->create(['dob' => now()->subYears(15)->toDateString(), 'firstName' => 'Riley', 'lastName' => 'Stone']);
    $guardian = User::factory()->adult()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id, 'ward_was_minor_at_creation' => true]);

    $counsellorUser = User::factory()->adult()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create(array_merge([
        'addedby_type' => User::class, 'addedby_id' => $minor->id, 'counsellor_id' => $counsellor->id,
        'status' => TherapyStatusEnum::in_session->value, 'anonymous' => false, 'public' => true, 'client_was_minor_at_creation' => true,
    ], $therapyAttributes));
    $session = Session::factory()->create([
        'for_type' => Therapy::class, 'for_id' => $therapy->id, 'addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id,
        'status' => SessionStatusEnum::in_session->value, 'type' => 'ONLINE', 'start_time' => now()->subMinutes(5), 'end_time' => now()->addHour(),
    ]);

    $group = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id, 'anonymous' => false, 'public' => true]);
    $group->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $adultMember = User::factory()->adult()->create();
    $group->users()->attach($minor->id, ['anonymous' => false, 'was_minor_at_join' => true]);
    $group->users()->attach($adultMember->id, ['anonymous' => false, 'was_minor_at_join' => false]);
    $groupSession = Session::factory()->create([
        'for_type' => GroupTherapy::class, 'for_id' => $group->id, 'addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id,
        'status' => SessionStatusEnum::in_session->value, 'type' => 'ONLINE', 'start_time' => now()->subMinutes(5), 'end_time' => now()->addHour(),
    ]);

    return compact('minor', 'guardian', 'counsellorUser', 'counsellor', 'therapy', 'session', 'group', 'adultMember', 'groupSession');
}

function gplDeleteGuardianship(User $guardian, User $ward): void
{
    $dto = new GetGuardianshipDTO;
    $dto->guardianship = Guardianship::query()->where('guardian_id', $guardian->id)->where('ward_id', $ward->id)->firstOrFail();
    DeleteGuardianshipAction::new()->execute($dto);
}

function gplSuperAdmin(): User
{
    return User::factory()->adult()->has(Administrator::factory())->create();
}

// The guardian is also a counsellor, reported by one of their own (adult) clients, resolved with
// "suspension recommended" -- the existing way a person becomes suspendable.
function gplReportAbout(User $guardian): Report
{
    $counsellor = $guardian->counsellor ?? Counsellor::factory()->create(['user_id' => $guardian->id]);
    $client = User::factory()->adult()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id]);

    $report = new Report(['description' => 'Conduct', 'data' => ['counsellorId' => $counsellor->id]]);
    $report->addedby()->associate($client);
    $report->reportable()->associate($therapy);
    $report->save();
    $report->forceFill(['status' => 'resolved', 'outcome' => 'suspension_recommended', 'resolution_note' => 'Serious'])->save();

    return $report;
}

function gplSuspendAccount(User $guardian): Suspension
{
    return ApplySuspensionAction::new()->execute(gplSuperAdmin(), gplReportAbout($guardian), SuspensionScopeEnum::account, 30, 'A reason the person will read.');
}

function gplMessage($test, User $user, $from, Session $session)
{
    return $test->actingAs($user)->postJson(route('api.messages.create'), [
        'content' => 'Hello', 'type' => 'NORMAL', 'fromId' => $from->id, 'fromType' => class_basename($from), 'forId' => $session->id, 'forType' => 'Session',
    ]);
}

beforeEach(function () {
    Notification::fake();
    Event::fake([GuardianAccessChangedEvent::class, TherapyAccessChangedEvent::class]);
});

test('removing the only guardianship pauses the minor once: told, their counsellor told, pages refreshed', function () {
    $f = gplFamily();

    gplDeleteGuardianship($f['guardian'], $f['minor']);

    expect($f['minor']->fresh()->isPausedForMissingGuardian())->toBeTrue();
    Notification::assertSentToTimes($f['minor'], GuardianAccessPausedNotification::class, 1);
    // SCRUM-381 (the user's decision): the counsellor runs the 1:1 and the group, so is told about both.
    Notification::assertSentToTimes($f['counsellorUser'], ClientAccessPausedNotification::class, 2);
    Notification::assertSentTo($f['counsellorUser'], ClientAccessPausedNotification::class, fn ($n) => ($n->toArray($f['counsellorUser'])['groupTherapyId'] ?? null) === $f['group']->id);
    Notification::assertNotSentTo($f['adultMember'], ClientAccessPausedNotification::class);
    // The minor's own channel, and (SCRUM-381) the group counsellor's own -- never the group's.
    Event::assertDispatchedTimes(GuardianAccessChangedEvent::class, 2);
    Event::assertDispatched(GuardianAccessChangedEvent::class, fn ($e) => $e->broadcastOn()[0]->name === "private-users.{$f['minor']->id}");
    Event::assertDispatched(GuardianAccessChangedEvent::class, fn ($e) => $e->broadcastOn()[0]->name === "private-users.{$f['counsellorUser']->id}");
    Event::assertDispatched(GuardianAccessChangedEvent::class, fn ($e) => $e->broadcastAs() === GuardianAccessChangedEvent::PAUSED && $e->broadcastWith() === []);
    // Only the 1:1's channel -- never the group's.
    Event::assertDispatchedTimes(TherapyAccessChangedEvent::class, 1);
    Event::assertDispatched(TherapyAccessChangedEvent::class, fn ($e) => $e->broadcastOn()[0]->name === "presence-therapies.{$f['therapy']->id}");
});

test('a minor with a second active guardian keeps access when one is removed', function () {
    $f = gplFamily();
    Guardianship::query()->create(['guardian_id' => User::factory()->adult()->create()->id, 'ward_id' => $f['minor']->id]);

    gplDeleteGuardianship($f['guardian'], $f['minor']);

    expect($f['minor']->fresh()->isPausedForMissingGuardian())->toBeFalse();
    Notification::assertNotSentTo($f['minor'], GuardianAccessPausedNotification::class);
    Event::assertNotDispatched(GuardianAccessChangedEvent::class);
    gplMessage($this, $f['minor'], $f['minor'], $f['session'])->assertSuccessful();
});

test('a guardian deleting their own account pauses the ward once, and their video consents for the ward lapse', function () {
    $f = gplFamily();
    $consent = VideoConsent::factory()->create(['ward_id' => $f['minor']->id, 'guardian_id' => $f['guardian']->id, 'consentable_type' => Therapy::class, 'consentable_id' => $f['therapy']->id]);

    $this->actingAs($f['guardian'])->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasNoErrors();

    expect($f['guardian']->fresh()->trashed())->toBeTrue()
        ->and($f['minor']->fresh()->isPausedForMissingGuardian())->toBeTrue()
        ->and($consent->fresh()->revoked_at)->not->toBeNull();
    Notification::assertSentToTimes($f['minor'], GuardianAccessPausedNotification::class, 1);
});

test('an administrator deleting a guardian pauses the ward once', function () {
    $f = gplFamily();
    $dto = new UpdateUserDTO;
    $dto->user = gplSuperAdmin();
    $dto->updatedUser = $f['guardian'];

    UserService::new()->deleteUserByAdmin($dto);

    expect($f['minor']->fresh()->isPausedForMissingGuardian())->toBeTrue();
    Notification::assertSentToTimes($f['minor'], GuardianAccessPausedNotification::class, 1);
});

test('an account suspension of the only guardian pauses the ward once; a consent is not lapsed by it', function () {
    $f = gplFamily();
    $consent = VideoConsent::factory()->create(['ward_id' => $f['minor']->id, 'guardian_id' => $f['guardian']->id, 'consentable_type' => Therapy::class, 'consentable_id' => $f['therapy']->id]);

    gplSuspendAccount($f['guardian']);

    expect($f['minor']->fresh()->isPausedForMissingGuardian())->toBeTrue()
        ->and($consent->fresh()->revoked_at)->toBeNull();
    Notification::assertSentToTimes($f['minor'], GuardianAccessPausedNotification::class, 1);
});

test('a counsellor-role suspension of a guardian does not pause their ward', function () {
    $f = gplFamily();

    ApplySuspensionAction::new()->execute(gplSuperAdmin(), gplReportAbout($f['guardian']), SuspensionScopeEnum::counsellor, 30, 'A reason the person will read.');

    expect($f['minor']->fresh()->isPausedForMissingGuardian())->toBeFalse();
    Notification::assertNotSentTo($f['minor'], GuardianAccessPausedNotification::class);
});

test('removing a guardian from a ward already paused does not pause them again', function () {
    $f = gplFamily();
    gplSuspendAccount($f['guardian']);

    gplDeleteGuardianship($f['guardian'], $f['minor']);

    Notification::assertSentToTimes($f['minor'], GuardianAccessPausedNotification::class, 1);
});

test('a guardianship accepted for a paused minor resumes them once', function () {
    $f = gplFamily();
    gplDeleteGuardianship($f['guardian'], $f['minor']);
    $newGuardian = User::factory()->adult()->create();
    $request = Request::factory()->create([
        'from_type' => User::class, 'from_id' => $f['minor']->id, 'to_type' => User::class, 'to_id' => $newGuardian->id,
        'type' => RequestTypeEnum::guardianship->value, 'status' => RequestStatusEnum::pending->value, 'data' => [],
    ]);

    RespondToGuardianshipRequestAction::new()->execute(RequestResponseDTO::new()->fromArray(['user' => $newGuardian, 'response' => 'accepted', 'request' => $request]));

    expect($f['minor']->fresh()->isPausedForMissingGuardian())->toBeFalse();
    Notification::assertSentToTimes($f['minor'], GuardianAccessResumedNotification::class, 1);
    // SCRUM-381: once for the 1:1 and once for the group this counsellor also runs.
    Notification::assertSentToTimes($f['counsellorUser'], ClientAccessResumedNotification::class, 2);
    Event::assertDispatched(GuardianAccessChangedEvent::class, fn ($e) => $e->broadcastAs() === GuardianAccessChangedEvent::RESUMED);
});

test('a paused minor can still ask for a guardian, and the guardian accepting through the route resumes them', function () {
    $f = gplFamily();
    gplDeleteGuardianship($f['guardian'], $f['minor']);
    $newGuardian = User::factory()->adult()->create();

    $this->actingAs($f['minor']->fresh())->postJson(route('api.users.guardianshiprequest', ['userId' => $f['minor']->id]), ['guardianId' => $newGuardian->id])->assertSuccessful();
    $request = Request::query()->where('type', RequestTypeEnum::guardianship->value)->where('from_id', $f['minor']->id)->sole();

    $this->actingAs($newGuardian)->postJson(route('requests.respond', ['requestId' => $request->id]), ['response' => 'accepted'])->assertSuccessful();

    expect($f['minor']->fresh()->isPausedForMissingGuardian())->toBeFalse();
    Notification::assertSentToTimes($f['minor'], GuardianAccessResumedNotification::class, 1);
});

test('a guardianship request can still be rejected while the minor is paused, and nothing resumes', function () {
    $f = gplFamily();
    gplDeleteGuardianship($f['guardian'], $f['minor']);
    $newGuardian = User::factory()->adult()->create();
    $request = Request::factory()->create([
        'from_type' => User::class, 'from_id' => $f['minor']->id, 'to_type' => User::class, 'to_id' => $newGuardian->id,
        'type' => RequestTypeEnum::guardianship->value, 'status' => RequestStatusEnum::pending->value, 'data' => [],
    ]);

    $this->actingAs($newGuardian)->postJson(route('requests.respond', ['requestId' => $request->id]), ['response' => 'rejected'])->assertSuccessful();

    expect($f['minor']->fresh()->isPausedForMissingGuardian())->toBeTrue();
    Notification::assertNotSentTo($f['minor'], GuardianAccessResumedNotification::class);
});

test('adding a second guardian to a minor who was not paused announces nothing', function () {
    $f = gplFamily();
    $request = Request::factory()->create([
        'from_type' => User::class, 'from_id' => $f['minor']->id, 'to_type' => User::class, 'to_id' => ($other = User::factory()->adult()->create())->id,
        'type' => RequestTypeEnum::guardianship->value, 'status' => RequestStatusEnum::pending->value, 'data' => [],
    ]);

    RespondToGuardianshipRequestAction::new()->execute(RequestResponseDTO::new()->fromArray(['user' => $other, 'response' => 'accepted', 'request' => $request]));

    Notification::assertNotSentTo($f['minor'], GuardianAccessResumedNotification::class);
});

test('lifting the guardian\'s account suspension resumes the ward once', function () {
    $f = gplFamily();
    $suspension = gplSuspendAccount($f['guardian']);

    LiftSuspensionAction::new()->execute(gplSuperAdmin(), $suspension, 'Lifted after review of the case.');

    expect($f['minor']->fresh()->isPausedForMissingGuardian())->toBeFalse();
    Notification::assertSentToTimes($f['minor'], GuardianAccessResumedNotification::class, 1);
});

test('the guardian\'s account suspension running out resumes the ward once, at the sweep', function () {
    $f = gplFamily();
    $suspension = gplSuspendAccount($f['guardian']);
    $suspension->forceFill(['ends_at' => now()->subMinute()])->save();

    SuspensionService::new()->endExpiredSuspensions();
    SuspensionService::new()->endExpiredSuspensions();

    Notification::assertSentToTimes($f['minor'], GuardianAccessResumedNotification::class, 1);
});

test('turning 18 lifts the pause without anything running', function () {
    $f = gplFamily();
    gplDeleteGuardianship($f['guardian'], $f['minor']);
    $f['minor']->forceFill(['dob' => now()->subYears(18)->subDay()->toDateString()])->save();

    gplMessage($this, $f['minor']->fresh(), $f['minor'], $f['session'])->assertSuccessful();
});

test('a paused minor can still open and read their therapy and its messages', function () {
    $f = gplFamily();
    Message::factory()->create(['from_type' => Counsellor::class, 'from_id' => $f['counsellor']->id, 'to_type' => User::class, 'to_id' => $f['minor']->id, 'for_type' => Session::class, 'for_id' => $f['session']->id, 'content' => 'Earlier']);
    gplDeleteGuardianship($f['guardian'], $f['minor']);
    $minor = $f['minor']->fresh();

    $this->actingAs($minor)->get(route('therapies.get', ['therapyId' => $f['therapy']->id]))->assertOk();
    $this->actingAs($minor)->getJson(route('api.session.messages.get', ['sessionId' => $f['session']->id]))->assertOk()->assertJsonFragment(['content' => 'Earlier']);
    $this->actingAs($minor)->getJson(route('api.sessions.get', ['therapyId' => $f['therapy']->id]))->assertOk();
});

test('ending or abandoning a session, and deleting a message, stay allowed while paused', function () {
    $f = gplFamily();
    $message = Message::factory()->create(['from_type' => User::class, 'from_id' => $f['minor']->id, 'to_type' => Counsellor::class, 'to_id' => $f['counsellor']->id, 'for_type' => Session::class, 'for_id' => $f['session']->id, 'content' => 'Mine']);
    gplDeleteGuardianship($f['guardian'], $f['minor']);
    $minor = $f['minor']->fresh();

    $this->actingAs($minor)->deleteJson(route('api.messages.delete', ['messageId' => $message->id]))->assertSuccessful();
    $this->actingAs($minor)->postJson(route('api.sessions.abandon', ['sessionId' => $f['session']->id]))->assertSuccessful();
});

test('in a group only the paused minor is refused; the other members carry on', function () {
    $f = gplFamily();
    gplDeleteGuardianship($f['guardian'], $f['minor']);

    gplMessage($this, $f['minor']->fresh(), $f['minor'], $f['groupSession'])->assertForbidden()->assertJsonPath('code', GuardianRequiredException::CODE);
    gplMessage($this, $f['adultMember'], $f['adultMember'], $f['groupSession'])->assertSuccessful();
    gplMessage($this, $f['counsellorUser'], $f['counsellor'], $f['groupSession'])->assertSuccessful();
});

test('an adult sending a message runs no guardianship query', function () {
    $client = User::factory()->adult()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id, 'public' => true]);
    $session = Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id, 'addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id, 'status' => SessionStatusEnum::in_session->value]);

    DB::enableQueryLog();
    gplMessage($this, $client, $client, $session)->assertSuccessful();
    gplMessage($this, $counsellor->user, $counsellor, $session)->assertSuccessful();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($queries->filter(fn (string $sql) => str_contains($sql, 'guardianship'))->all())->toBe([]);
});

test('the counsellor of an anonymous therapy is told without the client\'s name', function () {
    $f = gplFamily(['anonymous' => true]);

    gplDeleteGuardianship($f['guardian'], $f['minor']);

    Notification::assertSentTo($f['counsellorUser'], ClientAccessPausedNotification::class, function ($notification, $channels, $notifiable) use ($f) {
        $mail = implode(' ', $notification->toMail($notifiable)->introLines);

        return ! str_contains($mail, 'Riley') && ! str_contains($mail, 'Stone') && ! str_contains($mail, $f['guardian']->firstName)
            && $notification->toArray($notifiable)['clientName'] === ConstantsEnum::anonymousUserLabel->value;
    });
    Notification::assertSentTo($f['minor'], GuardianAccessPausedNotification::class, function ($notification, $channels, $notifiable) use ($f) {
        $mail = implode(' ', $notification->toMail($notifiable)->introLines).' '.implode(' ', $notification->toMail($notifiable)->outroLines);

        return ! str_contains($mail, $f['guardian']->firstName) && str_contains($mail, 'crisis line');
    });
});

test('a pause ends the 1:1 call and takes only the minor out of the group call', function () {
    $provider = gplVideoProvider();
    app()->instance(VideoProviderInterface::class, $provider);
    $f = gplFamily();
    $room = VideoSession::query()->create(['session_id' => $f['session']->id, 'provider' => 'daily', 'started_at' => now()]);
    $groupRoom = VideoSession::query()->create(['session_id' => $f['groupSession']->id, 'provider' => 'daily', 'started_at' => now()]);
    foreach ([[$room, $f['minor']], [$room, $f['counsellorUser']], [$groupRoom, $f['minor']], [$groupRoom, $f['adultMember']]] as [$videoSession, $user]) {
        VideoSessionParticipant::query()->create(['video_session_id' => $videoSession->id, 'participant_type' => User::class, 'participant_id' => $user->id, 'joined_at' => now()]);
    }

    gplDeleteGuardianship($f['guardian'], $f['minor']);

    expect($room->fresh()->ended_at)->not->toBeNull()
        ->and($groupRoom->fresh()->ended_at)->toBeNull()
        ->and($groupRoom->participantRowsFor($f['minor']->id)->whereNull('left_at')->exists())->toBeFalse()
        ->and($groupRoom->participantRowsFor($f['adultMember']->id)->whereNull('left_at')->exists())->toBeTrue()
        ->and($f['session']->fresh()->status)->toBe(SessionStatusEnum::in_session->value);
});

test('nothing is announced when the minor has a guardian again by the time the pause runs', function () {
    $f = gplFamily();

    HandleGuardianLossAction::new()->execute($f['minor'], RemoveGuardianFromWardAction::GUARDIANSHIP_REMOVED);

    Notification::assertNothingSentTo($f['minor']);
});

test('the therapy resource shows the viewer their own pause, and only the 1:1 counsellor the client\'s', function () {
    $f = gplFamily();
    gplDeleteGuardianship($f['guardian'], $f['minor']);
    $as = fn (User $user, $resource) => $resource->resolve(tap(HttpRequest::create('/'), fn ($r) => $r->setUserResolver(fn () => $user)));

    $minorView = $as($f['minor']->fresh(), new TherapyResource($f['therapy']->fresh()));
    $counsellorView = $as($f['counsellorUser']->fresh(), new TherapyResource($f['therapy']->fresh()));
    $memberView = $as($f['adultMember'], new GroupTherapyResource($f['group']->fresh()));

    expect($minorView['viewerAccessPaused'])->toBeTrue()
        ->and($minorView)->not->toHaveKey('clientAccessPaused')
        ->and($counsellorView['viewerAccessPaused'])->toBeFalse()
        ->and($counsellorView['clientAccessPaused'])->toBeTrue()
        ->and($memberView['viewerAccessPaused'])->toBeFalse()
        ->and($memberView)->not->toHaveKey('clientAccessPaused');
});

test('a refusal carries guardian_required in the redirect alert for an Inertia form, with the message unchanged', function () {
    $f = gplFamily();
    gplDeleteGuardianship($f['guardian'], $f['minor']);

    $this->actingAs($f['minor']->fresh())->from('/therapies/'.$f['therapy']->id)
        ->patch(route('therapies.update', ['therapyId' => $f['therapy']->id]), ['name' => 'Renamed'])
        ->assertSessionHasErrors(['alert' => GuardianRequiredException::PAUSED, 'alertCode' => GuardianRequiredException::CODE]);
});

test('the inventory command lists unguarded minors by id, split by whether a date of birth is known', function () {
    $f = gplFamily();
    gplDeleteGuardianship($f['guardian'], $f['minor']);
    $noDob = User::factory()->create();
    User::factory()->adult()->create();

    $exit = Artisan::call('guardians:report-unguarded-minors');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('No date of birth: ')
        ->and($output)->toContain('Known minor (under 18): 1 account(s), 1 with an open therapy or group.')
        ->and($output)->toMatch("/\\|\\s+{$f['minor']->id}\\s+\\|\\s+1\\s+\\|\\s+1\\s+\\|/")
        ->and($output)->toMatch("/\\|\\s+{$noDob->id}\\s+\\|/")
        ->and($output)->not->toContain($f['minor']->firstName);
});

test('a guardianship link can only be used by an adult with a verified email, and records the minor snapshot', function () {
    $f = gplFamily();
    gplDeleteGuardianship($f['guardian'], $f['minor']);
    $link = fn () => Link::query()->forceCreate(['type' => LinkTypeEnum::guardianship->value, 'state' => LinkStateEnum::active->value, 'uuid' => (string) Str::uuid(),
        'addedby_type' => User::class, 'addedby_id' => $f['minor']->id, 'for_type' => User::class, 'for_id' => $f['minor']->id]);
    $dto = fn (User $user, Link $link) => CreateLinkDTO::new()->fromArray(['user' => $user, 'link' => $link]);

    expect(fn () => PerformGuardianshipLinkAction::new()->execute($dto(User::factory()->create(), $link())))->toThrow(UserException::class);

    PerformGuardianshipLinkAction::new()->execute($dto($adult = User::factory()->adult()->create(), $link()));

    expect(Guardianship::query()->where('guardian_id', $adult->id)->sole()->ward_was_minor_at_creation)->toBeTrue()
        ->and($f['minor']->fresh()->isPausedForMissingGuardian())->toBeFalse();
    Notification::assertSentToTimes($f['minor'], GuardianAccessResumedNotification::class, 1);
});

test('a guardian who is not an adult, or is suspended, does not count; the ward is paused', function () {
    $ward = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);
    Guardianship::query()->create(['guardian_id' => User::factory()->create(['dob' => now()->subYears(16)->toDateString()])->id, 'ward_id' => $ward->id]);

    expect($ward->isPausedForMissingGuardian())->toBeTrue();
});

test('the pause decision reads are locking reads, subqueries included (MySQL)', function () {
    $ward = User::factory()->create();
    $grammar = new MySqlGrammar(DB::connection());
    $sql = $grammar->compileSelect($ward->activeGuardianships([7], lock: true)->toBase());

    // The guardianship rows, the guardian's users row and their suspensions -- each `lock in share mode`.
    expect(substr_count(strtolower($sql), 'lock in share mode'))->toBe(3)
        ->and($sql)->toContain('not in');
    expect(substr_count(strtolower($grammar->compileSelect($ward->activeGuardianships()->toBase())), 'lock in share mode'))->toBe(0);
});

test('a video consent from a guardian who no longer counts is ignored, and counts again once they do', function () {
    $f = gplFamily();
    VideoConsent::factory()->create(['ward_id' => $f['minor']->id, 'guardian_id' => $f['guardian']->id, 'consentable_type' => Therapy::class, 'consentable_id' => $f['therapy']->id]);
    $hasConsent = fn () => HasValidVideoConsentForSessionAction::new()->execute($f['session']->fresh());

    expect($hasConsent())->toBeTrue();
    $suspension = gplSuspendAccount($f['guardian']);
    expect($hasConsent())->toBeFalse();
    LiftSuspensionAction::new()->execute(gplSuperAdmin(), $suspension, 'Lifted after review of the case.');
    expect($hasConsent())->toBeTrue();
});

test('a group counsellor is told neutrally that a paused minor\'s request to join can\'t be accepted', function () {
    $f = gplFamily();
    $group = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $f['counsellor']->id, 'allow_anyone' => false]);
    $group->counsellors()->attach($f['counsellor']->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $request = Request::factory()->create([
        'from_type' => User::class, 'from_id' => $f['minor']->id, 'to_type' => Counsellor::class, 'to_id' => $f['counsellor']->id,
        'for_type' => GroupTherapy::class, 'for_id' => $group->id, 'type' => RequestTypeEnum::groupTherapyMembership->value, 'status' => RequestStatusEnum::pending->value, 'data' => [],
    ]);
    gplDeleteGuardianship($f['guardian'], $f['minor']);

    $this->actingAs($f['counsellorUser'])->postJson(route('requests.respond', ['requestId' => $request->id]), ['response' => 'accepted'])
        ->assertForbidden()->assertJsonPath('error', GuardianRequiredException::REQUEST_UNAVAILABLE)->assertJsonMissingPath('code');
});

test('a paused minor removed while their join lock is held is taken out of the call by the queued retry', function () {
    config(['video.join_lock_wait_seconds' => 0]);
    app()->instance(VideoProviderInterface::class, gplVideoProvider());
    Queue::fake([RemovePersonFromLiveCallJob::class]);
    $f = gplFamily();
    $room = VideoSession::query()->create(['session_id' => $f['session']->id, 'provider' => 'daily', 'started_at' => now()]);
    VideoSessionParticipant::query()->create(['video_session_id' => $room->id, 'participant_type' => User::class, 'participant_id' => $f['minor']->id, 'joined_at' => now()]);
    $lock = Cache::store(config('cache.scheduler_store'))->lock("video-join:{$f['session']->id}:{$f['minor']->id}", 90);
    $lock->get();

    gplDeleteGuardianship($f['guardian'], $f['minor']);

    expect($room->fresh()->ended_at)->toBeNull();
    Queue::assertPushed(RemovePersonFromLiveCallJob::class, 1);

    $lock->release();
    (new RemovePersonFromLiveCallJob($f['session']->id, $f['minor']->id, RemovePersonFromLiveCallsAction::GUARDIAN_LOSS))->handle();

    expect($room->fresh()->ended_at)->not->toBeNull();
});

test('the retry does nothing once the minor has a guardian again', function () {
    app()->instance(VideoProviderInterface::class, gplVideoProvider());
    $f = gplFamily();
    $room = VideoSession::query()->create(['session_id' => $f['session']->id, 'provider' => 'daily', 'started_at' => now()]);
    VideoSessionParticipant::query()->create(['video_session_id' => $room->id, 'participant_type' => User::class, 'participant_id' => $f['minor']->id, 'joined_at' => now()]);

    (new RemovePersonFromLiveCallJob($f['session']->id, $f['minor']->id, RemovePersonFromLiveCallsAction::GUARDIAN_LOSS))->handle();

    expect($room->fresh()->ended_at)->toBeNull();
});

test('deleting a guardianship of a deleted ward removes the row and pauses nothing', function () {
    $f = gplFamily();
    $f['minor']->delete();

    gplDeleteGuardianship($f['guardian'], $f['minor']);

    expect(Guardianship::query()->where('ward_id', $f['minor']->id)->exists())->toBeFalse();
    Notification::assertNothingSentTo($f['minor']);
});

test('a counsellor can\'t be taken on by link while the therapy is paused, and a paused minor can\'t create one', function () {
    $f = gplFamily();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $f['minor']->id, 'counsellor_id' => null, 'status' => TherapyStatusEnum::pending->value, 'public' => true]);
    $link = Link::query()->forceCreate(['type' => LinkTypeEnum::therapyCounsellor->value, 'state' => LinkStateEnum::active->value, 'uuid' => (string) Str::uuid(),
        'addedby_type' => User::class, 'addedby_id' => $f['minor']->id, 'for_type' => Therapy::class, 'for_id' => $therapy->id]);
    gplDeleteGuardianship($f['guardian'], $f['minor']);

    expect(fn () => PerformTherapyCounsellorLinkAction::new()->execute(CreateLinkDTO::new()->fromArray(['user' => $f['counsellorUser'], 'link' => $link])))
        ->toThrow(GuardianRequiredException::class, GuardianRequiredException::LINK_UNAVAILABLE);
    expect($therapy->fresh()->counsellor_id)->toBeNull();

    // SCRUM-418: told neutrally on the page and on confirming -- not that the client is a minor without a guardian.
    $this->actingAs($f['counsellorUser'])->get(route('links.get', ['uuid' => $link->uuid]))->assertOk()
        ->assertInertia(fn ($page) => $page->where('refusal', GuardianRequiredException::LINK_UNAVAILABLE)->missing('summary'));
    $this->postJson(route('api.links.join', ['uuid' => $link->uuid]))
        ->assertForbidden()->assertJsonPath('message', GuardianRequiredException::LINK_UNAVAILABLE)->assertJsonMissingPath('code');
});
