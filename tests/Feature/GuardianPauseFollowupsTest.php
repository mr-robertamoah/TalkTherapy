<?php

use App\Actions\Guardianship\RemoveGuardianFromWardAction;
use App\Actions\Link\EnsureUserCanCreateLinkForAction;
use App\Actions\Request\RespondToAgeVerificationRequestAction;
use App\Actions\Request\RespondToDobChangeRequestAction;
use App\Actions\Request\RespondToGuardianshipRequestAction;
use App\Actions\Suspension\ApplySuspensionAction;
use App\Actions\Suspension\LiftSuspensionAction;
use App\Actions\Therapy\IsTherapyBlockedForAction;
use App\Actions\User\DeleteGuardianshipAction;
use App\Actions\User\UpdateUserAction;
use App\Actions\Video\RemovePersonFromLiveCallsAction;
use App\Contracts\VideoProviderInterface;
use App\DTOs\GetGuardianshipDTO;
use App\DTOs\RequestResponseDTO;
use App\DTOs\UpdateUserDTO;
use App\Enums\LinkStateEnum;
use App\Enums\LinkTypeEnum;
use App\Enums\ReportStatusEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\SessionStatusEnum;
use App\Enums\SuspensionScopeEnum;
use App\Enums\TherapyStatusEnum;
use App\Events\GuardianAccessChangedEvent;
use App\Events\TherapyAccessChangedEvent;
use App\Exceptions\GuardianRequiredException;
use App\Http\Resources\GroupTherapyResource;
use App\Http\Resources\TherapyResource;
use App\Jobs\HandleGuardianLossJob;
use App\Jobs\RemovePersonFromLiveCallJob;
use App\Jobs\StoreVisitationJob;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\File;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\HowToStep;
use App\Models\Link;
use App\Models\Organization;
use App\Models\Report;
use App\Models\ReportStatusHistory;
use App\Models\Request;
use App\Models\Session;
use App\Models\Suspension;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoSession;
use App\Models\VideoSessionParticipant;
use App\Notifications\ClientAccessPausedNotification;
use App\Notifications\ClientAccessResumedNotification;
use App\Notifications\GuardianAccessPausedNotification;
use App\Notifications\GuardianAccessResumedNotification;
use App\Notifications\SuspensionEndedNotification;
use App\Services\SuspensionService;
use App\Support\BestEffort;
use App\Support\FailureResponse;
use App\Support\SchedulerOnce;
use App\Support\WardRef;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// SCRUM-382: follow-ups to SCRUM-288's pause for a missing guardian -- the ways into the pause that
// skipped the loss handling, group links by a paused creator, the one live-call removal, the shared
// announce step, pauseStateFor(), the queued loss handling, the suspension lock order and tidy-ups.
// Helpers are file-local and gpf-prefixed (--parallel).

function gpfVideoProvider(): VideoProviderInterface
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

// A client (adult by default) with no guardian, a 1:1 with a counsellor and a live session.
function gpfClient(?string $dob = null): array
{
    $client = User::factory()->create(['dob' => $dob ?? now()->subYears(30)->toDateString(), 'firstName' => 'Robin', 'lastName' => 'Vale']);
    $counsellorUser = User::factory()->adult()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id,
        'status' => TherapyStatusEnum::in_session->value, 'anonymous' => false, 'public' => true,
    ]);
    $session = Session::factory()->create([
        'for_type' => Therapy::class, 'for_id' => $therapy->id, 'addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id,
        'status' => SessionStatusEnum::in_session->value, 'type' => 'ONLINE', 'start_time' => now()->subMinutes(5), 'end_time' => now()->addHour(),
    ]);

    return compact('client', 'counsellorUser', 'counsellor', 'therapy', 'session');
}

function gpfInCall(Session $session, User ...$people): VideoSession
{
    $room = VideoSession::query()->create(['session_id' => $session->id, 'provider' => 'daily', 'started_at' => now()]);
    foreach ($people as $person) {
        VideoSessionParticipant::query()->create(['video_session_id' => $room->id, 'participant_type' => User::class, 'participant_id' => $person->id, 'joined_at' => now()]);
    }

    return $room;
}

function gpfMinorWithGuardian(): array
{
    $f = gpfClient(now()->subYears(15)->toDateString());
    $f['guardian'] = User::factory()->adult()->create();
    Guardianship::query()->create(['guardian_id' => $f['guardian']->id, 'ward_id' => $f['client']->id, 'ward_was_minor_at_creation' => true]);

    return $f;
}

function gpfRemoveGuardianship(User $guardian, User $ward): void
{
    $dto = new GetGuardianshipDTO;
    $dto->guardianship = Guardianship::query()->where('guardian_id', $guardian->id)->where('ward_id', $ward->id)->firstOrFail();
    DeleteGuardianshipAction::new()->execute($dto);
}

function gpfAdmin(): User
{
    return User::factory()->adult()->has(Administrator::factory())->create();
}

function gpfDobChangeRequest(User $user, ?string $newDob): Request
{
    return Request::factory()->create([
        'from_type' => User::class, 'from_id' => $user->id, 'to_type' => null, 'to_id' => null,
        'for_type' => User::class, 'for_id' => $user->id,
        'type' => RequestTypeEnum::dobChange->value, 'status' => RequestStatusEnum::pending->value,
        'data' => ['newDob' => $newDob, 'priorDob' => $user->dob?->toDateString()],
    ]);
}

function gpfApprove(Request $request, string $action = RespondToDobChangeRequestAction::class): void
{
    $action::new()->execute(RequestResponseDTO::new()->fromArray(['user' => gpfAdmin(), 'response' => 'accepted', 'request' => $request]));
}

function gpfPausedGroupCreator(): array
{
    $creator = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $creator->id, 'status' => TherapyStatusEnum::pending->value]);

    return [$creator, $group];
}

function gpfGroupLink(GroupTherapy $group, User $creator, string $type): Link
{
    return Link::query()->forceCreate(['type' => $type, 'state' => LinkStateEnum::active->value, 'uuid' => (string) Str::uuid(),
        'addedby_type' => User::class, 'addedby_id' => $creator->id, 'for_type' => GroupTherapy::class, 'for_id' => $group->id]);
}

// A counsellor, reported by one of their own clients and resolved with "suspension recommended".
function gpfReportAbout(User $person): Report
{
    $counsellor = $person->counsellor ?? Counsellor::factory()->create(['user_id' => $person->id]);
    $client = User::factory()->adult()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id]);

    $report = new Report(['description' => 'Conduct', 'data' => ['counsellorId' => $counsellor->id]]);
    $report->addedby()->associate($client);
    $report->reportable()->associate($therapy);
    $report->save();
    $report->forceFill(['status' => ReportStatusEnum::resolved->value, 'outcome' => 'suspension_recommended', 'resolution_note' => 'Serious'])->save();

    return $report;
}

function gpfSuspend(User $person, SuspensionScopeEnum $scope = SuspensionScopeEnum::account): Suspension
{
    return ApplySuspensionAction::new()->execute(gpfAdmin(), gpfReportAbout($person), $scope, 30, 'A reason the person will read.');
}

function gpfView(User $viewer, $resource): array
{
    return $resource->resolve(tap(HttpRequest::create('/'), fn ($r) => $r->setUserResolver(fn () => $viewer)));
}

beforeEach(function () {
    Notification::fake();
    Event::fake([GuardianAccessChangedEvent::class, TherapyAccessChangedEvent::class]);
});

// --- 1. Ways into the pause that skipped the loss handling ------------------------------------

test('an approved dob change to under 18 pauses the client once: call ended, both told, pages refreshed', function () {
    app()->instance(VideoProviderInterface::class, gpfVideoProvider());
    $f = gpfClient();
    $room = gpfInCall($f['session'], $f['client'], $f['counsellorUser']);

    gpfApprove(gpfDobChangeRequest($f['client'], now()->subYears(16)->toDateString()));

    expect($f['client']->fresh()->isPausedForMissingGuardian())->toBeTrue()
        ->and($room->fresh()->ended_at)->not->toBeNull();
    Notification::assertSentToTimes($f['client'], GuardianAccessPausedNotification::class, 1);
    Notification::assertSentToTimes($f['counsellorUser'], ClientAccessPausedNotification::class, 1);
    Event::assertDispatchedTimes(GuardianAccessChangedEvent::class, 1);
    Event::assertDispatchedTimes(TherapyAccessChangedEvent::class, 1);
});

test('an approved change that clears the dob pauses them too', function () {
    $f = gpfClient();

    gpfApprove(gpfDobChangeRequest($f['client'], null));

    expect($f['client']->fresh()->dob)->toBeNull();
    Notification::assertSentToTimes($f['client'], GuardianAccessPausedNotification::class, 1);
    Notification::assertSentToTimes($f['counsellorUser'], ClientAccessPausedNotification::class, 1);
});

test('an admin age verification of an under-18 dob pauses them once', function () {
    $f = gpfClient();
    $request = Request::factory()->create([
        'from_type' => User::class, 'from_id' => $f['client']->id, 'to_type' => null, 'to_id' => null,
        'for_type' => User::class, 'for_id' => $f['client']->id,
        'type' => RequestTypeEnum::ageVerification->value, 'status' => RequestStatusEnum::pending->value,
        'data' => ['attestation' => 'This is my real date of birth.', 'attestedDob' => now()->subYears(14)->toDateString()],
    ]);

    gpfApprove($request, RespondToAgeVerificationRequestAction::class);

    expect($f['client']->fresh()->dob_verified_at)->not->toBeNull();
    Notification::assertSentToTimes($f['client'], GuardianAccessPausedNotification::class, 1);
});

test('a dob change that leaves them with an active guardian, or still an adult, or already paused, announces nothing', function () {
    $guarded = gpfClient();
    Guardianship::query()->create(['guardian_id' => User::factory()->adult()->create()->id, 'ward_id' => $guarded['client']->id]);
    gpfApprove(gpfDobChangeRequest($guarded['client'], now()->subYears(16)->toDateString()));

    $adult = gpfClient();
    gpfApprove(gpfDobChangeRequest($adult['client'], now()->subYears(40)->toDateString()));

    $alreadyPaused = gpfClient(now()->subYears(15)->toDateString());
    gpfApprove(gpfDobChangeRequest($alreadyPaused['client'], now()->subYears(14)->toDateString()));

    Notification::assertNotSentTo([$guarded['client'], $adult['client'], $alreadyPaused['client']], GuardianAccessPausedNotification::class);
    Event::assertNotDispatched(GuardianAccessChangedEvent::class);
});

test('a direct dob edit that pauses someone (an account with nothing to approve) queues the loss handling', function () {
    Queue::fake([HandleGuardianLossJob::class]);
    $user = User::factory()->adult()->create(['email_verified_at' => now()]);

    $this->actingAs($user)->patch(route('profile.update'), [
        'firstName' => $user->firstName, 'lastName' => $user->lastName, 'email' => $user->email, 'dob' => '',
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->dob)->toBeNull();
    Queue::assertPushed(HandleGuardianLossJob::class, 1);
});

test('the backfill lists ids on a dry run, then announces each paused account with something open once', function () {
    $paused = gpfClient(now()->subYears(15)->toDateString());
    $pausedNothingOpen = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);
    $guarded = gpfMinorWithGuardian();

    expect(Artisan::call('guardians:announce-existing-pauses', ['--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain("Would announce the pause of 1 account(s): {$paused['client']->id}");
    Notification::assertNothingSent();

    Artisan::call('guardians:announce-existing-pauses');
    expect(Artisan::output())->toContain("Queued the pause announcement for 1 account(s): {$paused['client']->id}");
    Artisan::call('guardians:announce-existing-pauses');
    expect(Artisan::output())->toContain('Queued the pause announcement for 0 account(s). Skipped 1 already announced.');

    Notification::assertSentToTimes($paused['client'], GuardianAccessPausedNotification::class, 1);
    Notification::assertSentToTimes($paused['counsellorUser'], ClientAccessPausedNotification::class, 1);
    Notification::assertNothingSentTo($pausedNothingOpen);
    Notification::assertNothingSentTo($guarded['client']);
});

// --- 2. Group links by a paused creator -------------------------------------------------------

test('a paused minor can\'t create any link into a group they created', function () {
    [$creator, $group] = gpfPausedGroupCreator();

    foreach (EnsureUserCanCreateLinkForAction::GROUP_TYPES as $type) {
        $response = $this->actingAs($creator)->postJson(route('api.links.create'), [
            'type' => $type, 'addedbyType' => 'User', 'addedbyId' => $creator->id, 'forType' => 'GroupTherapy', 'forId' => $group->id,
        ])->assertForbidden();

        // SCRUM-383: an admin link is refused for everyone (there is no group-admin role), before the pause.
        $type === LinkTypeEnum::groupTherapyAdmin->value
            ? $response->assertJsonPath('message', EnsureUserCanCreateLinkForAction::NOT_ALLOWED)
            : $response->assertJsonPath('code', GuardianRequiredException::CODE);
    }

    expect(Link::query()->where('for_type', GroupTherapy::class)->where('for_id', $group->id)->exists())->toBeFalse();
});

test('a group link can\'t be used while the group\'s creator is paused; it can once they have a guardian', function () {
    [$creator, $group] = gpfPausedGroupCreator();
    $link = gpfGroupLink($group, $creator, LinkTypeEnum::groupTherapyParticipant->value);
    $counsellorLink = gpfGroupLink($group, $creator, LinkTypeEnum::groupTherapyCounsellor->value);
    $adult = User::factory()->adult()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);

    $this->actingAs($adult)->getJson(route('links.get', ['uuid' => $link->uuid]))
        ->assertForbidden()->assertJsonPath('message', GuardianRequiredException::LINK_UNAVAILABLE)->assertJsonMissingPath('code');
    // SCRUM-418: a counsellor link's page says so; confirming is refused the same way.
    $this->actingAs($counsellor->user)->get(route('links.get', ['uuid' => $counsellorLink->uuid]))->assertOk()
        ->assertInertia(fn ($page) => $page->where('refusal', GuardianRequiredException::LINK_UNAVAILABLE)->missing('summary'));
    $this->postJson(route('api.links.join', ['uuid' => $counsellorLink->uuid]))
        ->assertForbidden()->assertJsonPath('message', GuardianRequiredException::LINK_UNAVAILABLE)->assertJsonMissingPath('code');

    Guardianship::query()->create(['guardian_id' => User::factory()->adult()->create()->id, 'ward_id' => $creator->id]);
    // SCRUM-383: opening a member link takes them to the group's page to confirm.
    $this->actingAs($adult)->getJson(route('links.get', ['uuid' => $link->uuid]))
        ->assertRedirect(route('group.therapies.get', ['groupTherapyId' => $group->id, 'invitation' => $link->uuid]));
});

test('a paused minor can\'t use someone else\'s group participant or admin link; a counsellor link isn\'t about their age', function () {
    $creator = User::factory()->adult()->create();
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $creator->id]);
    $paused = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);

    $this->actingAs($paused)->getJson(route('links.get', ['uuid' => gpfGroupLink($group, $creator, LinkTypeEnum::groupTherapyParticipant->value)->uuid]))
        ->assertStatus(422)->assertJsonPath('code', GuardianRequiredException::CODE);
    // SCRUM-383: an admin link no longer works for anyone.
    $this->actingAs($paused)->getJson(route('links.get', ['uuid' => gpfGroupLink($group, $creator, LinkTypeEnum::groupTherapyAdmin->value)->uuid]))
        ->assertStatus(422)->assertJsonPath('message', 'This link is no longer active.');

    expect($group->fresh()->users()->whereKey($paused->id)->exists())->toBeFalse();
});

// --- 3. One live-call removal -----------------------------------------------------------------

// The client of the 1:1 is the one suspended (their own counsellor sessions, none here, would be
// ended by the suspension's effects): they are ejected, and the room is not ended.
test('a suspension ejects the person from a 1:1 call (the room carries on) under their join lock', function () {
    $provider = gpfVideoProvider();
    app()->instance(VideoProviderInterface::class, $provider);
    $f = gpfClient();
    $room = gpfInCall($f['session'], $f['client'], $f['counsellorUser']);

    gpfSuspend($f['client']);

    expect($room->fresh()->ended_at)->toBeNull()
        ->and($room->participantRowsFor($f['client']->id)->whereNull('left_at')->exists())->toBeFalse()
        ->and($room->participantRowsFor($f['counsellorUser']->id)->whereNull('left_at')->exists())->toBeTrue()
        ->and($provider->removed)->toBe([[$room->id, $f['client']->id]]);
});

test('a counsellor-role suspension leaves the person in a call they are in as a client', function () {
    app()->instance(VideoProviderInterface::class, gpfVideoProvider());
    $f = gpfClient();
    $f['client']->counsellor()->save(Counsellor::factory()->make());
    $room = gpfInCall($f['session'], $f['client']);

    gpfSuspend($f['client'], SuspensionScopeEnum::counsellor);

    expect($room->participantRowsFor($f['client']->id)->whereNull('left_at')->exists())->toBeTrue();
});

test('a suspension removal whose join lock is busy is retried from the queue, and the retry does nothing once lifted', function () {
    config(['video.join_lock_wait_seconds' => 0]);
    app()->instance(VideoProviderInterface::class, gpfVideoProvider());
    Queue::fake([RemovePersonFromLiveCallJob::class]);
    $f = gpfClient();
    $room = gpfInCall($f['session'], $f['client']);
    $lock = Cache::store(config('cache.scheduler_store'))->lock("video-join:{$f['session']->id}:{$f['client']->id}", 90);
    $lock->get();

    $suspension = gpfSuspend($f['client']);

    expect($room->participantRowsFor($f['client']->id)->whereNull('left_at')->exists())->toBeTrue();
    Queue::assertPushed(RemovePersonFromLiveCallJob::class, 1);

    $lock->release();
    LiftSuspensionAction::new()->execute(gpfAdmin(), $suspension, 'Lifted after a second look.');
    (new RemovePersonFromLiveCallJob($f['session']->id, $f['client']->id, RemovePersonFromLiveCallsAction::SUSPENSION, $suspension->id))->handle();

    expect($room->participantRowsFor($f['client']->id)->whereNull('left_at')->exists())->toBeTrue();
});

test('the queued suspension retry ejects them while the suspension still applies', function () {
    app()->instance(VideoProviderInterface::class, gpfVideoProvider());
    Queue::fake([RemovePersonFromLiveCallJob::class]);
    config(['video.join_lock_wait_seconds' => 0]);
    $f = gpfClient();
    $room = gpfInCall($f['session'], $f['client']);
    $lock = Cache::store(config('cache.scheduler_store'))->lock("video-join:{$f['session']->id}:{$f['client']->id}", 90);
    $lock->get();
    $suspension = gpfSuspend($f['client']);
    $lock->release();

    (new RemovePersonFromLiveCallJob($f['session']->id, $f['client']->id, RemovePersonFromLiveCallsAction::SUSPENSION, $suspension->id))->handle();

    expect($room->participantRowsFor($f['client']->id)->whereNull('left_at')->exists())->toBeFalse()
        ->and($room->fresh()->ended_at)->toBeNull();
});

// --- 4. Shared announce step ------------------------------------------------------------------

test('resuming tells the minor and, separately, the counsellor, with the copy unchanged', function () {
    $f = gpfMinorWithGuardian();
    gpfRemoveGuardianship($f['guardian'], $f['client']);
    $newGuardian = User::factory()->adult()->create();
    $request = Request::factory()->create([
        'from_type' => User::class, 'from_id' => $f['client']->id, 'to_type' => User::class, 'to_id' => $newGuardian->id,
        'type' => RequestTypeEnum::guardianship->value, 'status' => RequestStatusEnum::pending->value, 'data' => [],
    ]);

    RespondToGuardianshipRequestAction::new()->execute(RequestResponseDTO::new()->fromArray(['user' => $newGuardian, 'response' => 'accepted', 'request' => $request]));

    Notification::assertSentToTimes($f['client'], GuardianAccessResumedNotification::class, 1);
    Notification::assertNotSentTo($f['client'], ClientAccessResumedNotification::class);
    Notification::assertNotSentTo($f['counsellorUser'], GuardianAccessResumedNotification::class);
    Notification::assertSentTo($f['counsellorUser'], ClientAccessResumedNotification::class, function ($notification, $channels, $notifiable) use ($f) {
        $mail = $notification->toMail($notifiable);

        return $mail->subject === 'A paused therapy has started again'
            && $mail->introLines === ["Your therapy with {$f['client']->name} is no longer paused."]
            && $notification->toArray($notifiable) === ['type' => 'guardian_access.client_resumed', 'therapyId' => $f['therapy']->id, 'clientName' => $f['client']->name, 'wardRef' => WardRef::of($f['client'])];
    });
    Notification::assertSentTo($f['client'], GuardianAccessResumedNotification::class, function ($notification, $channels, $notifiable) {
        return $notification->toMail($notifiable)->subject === 'Your therapy on TalkTherapy has started again'
            && $notification->toArray($notifiable) === ['type' => 'guardian_access.resumed'];
    });
});

test('a best-effort step that fails is logged and swallowed, and the next step still runs', function () {
    Log::spy();
    $ran = false;

    $result = BestEffort::attempt(fn () => throw new RuntimeException('Reverb is down'), 'broadcast', ['ward' => 7]);
    BestEffort::attempt(function () use (&$ran) {
        $ran = true;
    }, 'notify');

    expect($result)->toBeNull()->and($ran)->toBeTrue();
    Log::shouldHaveReceived('warning')->once()->with('A best-effort step failed.', ['step' => 'broadcast', 'ward' => 7, 'error' => 'Reverb is down']);
});

test('a broadcast failing during a pause still lets the people be told', function () {
    Event::fake([TherapyAccessChangedEvent::class]);
    Event::listen(GuardianAccessChangedEvent::class, fn () => throw new RuntimeException('Reverb is down'));
    $f = gpfMinorWithGuardian();

    gpfRemoveGuardianship($f['guardian'], $f['client']);

    Notification::assertSentToTimes($f['client'], GuardianAccessPausedNotification::class, 1);
    Notification::assertSentToTimes($f['counsellorUser'], ClientAccessPausedNotification::class, 1);
});

// --- 4b. A resume is only announced when nothing else still blocks the therapy ------------------

function gpfAcceptGuardian(User $ward): void
{
    $newGuardian = User::factory()->adult()->create();
    $request = Request::factory()->create([
        'from_type' => User::class, 'from_id' => $ward->id, 'to_type' => User::class, 'to_id' => $newGuardian->id,
        'type' => RequestTypeEnum::guardianship->value, 'status' => RequestStatusEnum::pending->value, 'data' => [],
    ]);

    RespondToGuardianshipRequestAction::new()->execute(RequestResponseDTO::new()->fromArray(['user' => $newGuardian, 'response' => 'accepted', 'request' => $request]));
}

test('a guardian coming back is not announced to a ward whose own account is suspended', function () {
    $f = gpfMinorWithGuardian();
    $guardianSuspension = gpfSuspend($f['guardian']);
    gpfSuspend($f['client']);
    Event::fake([GuardianAccessChangedEvent::class, TherapyAccessChangedEvent::class]);

    LiftSuspensionAction::new()->execute(gpfAdmin(), $guardianSuspension, 'Lifted after a second look.');

    expect($f['client']->fresh()->isPausedForMissingGuardian())->toBeFalse();
    Notification::assertNotSentTo($f['client'], GuardianAccessResumedNotification::class);
    Notification::assertNotSentTo($f['counsellorUser'], ClientAccessResumedNotification::class);
    Event::assertNotDispatched(GuardianAccessChangedEvent::class, fn ($e) => $e->broadcastAs() === GuardianAccessChangedEvent::RESUMED);
});

test('a 1:1 whose counsellor is suspended is left out of the resume; with nothing else open nobody is told', function () {
    $f = gpfMinorWithGuardian();
    gpfRemoveGuardianship($f['guardian'], $f['client']);
    gpfSuspend($f['counsellorUser'], SuspensionScopeEnum::counsellor);
    Event::fake([GuardianAccessChangedEvent::class, TherapyAccessChangedEvent::class]);

    gpfAcceptGuardian($f['client']);

    Notification::assertNotSentTo($f['client'], GuardianAccessResumedNotification::class);
    Notification::assertNotSentTo($f['counsellorUser'], ClientAccessResumedNotification::class);
    Event::assertNotDispatched(GuardianAccessChangedEvent::class);
    Event::assertNotDispatched(TherapyAccessChangedEvent::class);
});

test('with a group still able to carry on, the minor is told, but not about the 1:1 whose counsellor is suspended', function () {
    $f = gpfMinorWithGuardian();
    $groupCounsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $group = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $groupCounsellor->id, 'status' => TherapyStatusEnum::in_session->value]);
    $group->users()->attach($f['client']->id, ['anonymous' => false, 'was_minor_at_join' => true]);
    gpfRemoveGuardianship($f['guardian'], $f['client']);
    gpfSuspend($f['counsellorUser'], SuspensionScopeEnum::counsellor);
    Event::fake([GuardianAccessChangedEvent::class, TherapyAccessChangedEvent::class]);

    gpfAcceptGuardian($f['client']);

    Notification::assertSentToTimes($f['client'], GuardianAccessResumedNotification::class, 1);
    Notification::assertNotSentTo($f['counsellorUser'], ClientAccessResumedNotification::class);
    Event::assertDispatched(GuardianAccessChangedEvent::class, fn ($e) => $e->broadcastAs() === GuardianAccessChangedEvent::RESUMED);
    Event::assertNotDispatched(TherapyAccessChangedEvent::class);
});

test('IsTherapyBlockedForAction: ended, own account suspension, suspended 1:1 counsellor, paused -- and clear otherwise', function () {
    $f = gpfClient();
    $blocked = fn () => IsTherapyBlockedForAction::new()->execute($f['client']->fresh(), $f['therapy']->fresh());

    expect($blocked())->toBeFalse();

    $f['therapy']->update(['status' => TherapyStatusEnum::ended->value]);
    expect($blocked())->toBeTrue();
    $f['therapy']->update(['status' => TherapyStatusEnum::in_session->value]);

    $f['client']->update(['dob' => now()->subYears(15)->toDateString()]);
    expect($blocked())->toBeTrue();
    $f['client']->update(['dob' => now()->subYears(30)->toDateString()]);

    $suspension = gpfSuspend($f['counsellorUser'], SuspensionScopeEnum::counsellor);
    expect($blocked())->toBeTrue();
    LiftSuspensionAction::new()->execute(gpfAdmin(), $suspension, 'Lifted after a second look.');
    expect($blocked())->toBeFalse();

    gpfSuspend($f['client']);
    expect($blocked())->toBeTrue();
});

test('a paused minor\'s own suspension ending says it is over without promising their therapy carries on', function () {
    $paused = gpfClient(now()->subYears(15)->toDateString());
    $adult = gpfClient();
    $pausedLifted = gpfSuspend($paused['client']);
    $adultLifted = gpfSuspend($adult['client']);
    $pausedExpiring = gpfClient(now()->subYears(15)->toDateString());
    gpfSuspend($pausedExpiring['client']);

    LiftSuspensionAction::new()->execute(gpfAdmin(), $pausedLifted, 'Lifted after a second look.');
    LiftSuspensionAction::new()->execute(gpfAdmin(), $adultLifted, 'Lifted after a second look.');
    $this->travel(31)->days();
    SuspensionService::new()->endExpiredSuspensions();

    $staysPaused = fn ($notification, $channels, $notifiable) => ($notification->toArray($notifiable)['therapyPaused'] ?? false) === true
        && in_array('You can sign in and use TalkTherapy again. Your therapy stays paused until a guardian is added to your account. Sessions cancelled during the suspension are not restored.', $notification->toMail($notifiable)->introLines, true);

    Notification::assertSentTo($paused['client'], SuspensionEndedNotification::class, $staysPaused);
    Notification::assertSentTo($pausedExpiring['client'], SuspensionEndedNotification::class, $staysPaused);
    Notification::assertSentTo($adult['client'], SuspensionEndedNotification::class, fn ($notification, $channels, $notifiable) => ! array_key_exists('therapyPaused', $notification->toArray($notifiable))
        && in_array('You can use TalkTherapy as before. Sessions cancelled during the suspension are not restored.', $notification->toMail($notifiable)->introLines, true));
    Notification::assertNotSentTo([$paused['client'], $pausedExpiring['client']], GuardianAccessResumedNotification::class);
});

// --- 5. pauseStateFor: page state and gate agree ----------------------------------------------

test('someone acting as the therapy\'s counsellor is never shown as paused themselves, matching the gate', function () {
    // A counsellor whose own account would count as a minor with no guardian (no date of birth).
    $counsellorUser = User::factory()->create(['dob' => null]);
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $f = gpfClient();
    $f['therapy']->update(['counsellor_id' => $counsellor->id]);
    $group = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id]);

    $therapyView = gpfView($counsellorUser, new TherapyResource($f['therapy']->fresh()));
    $groupView = gpfView($counsellorUser, new GroupTherapyResource($group->fresh()));

    expect($therapyView['viewerAccessPaused'])->toBeFalse()
        ->and($therapyView['clientAccessPaused'])->toBeFalse()
        ->and($groupView['viewerAccessPaused'])->toBeFalse()
        ->and($f['therapy']->fresh()->pauseStateFor($counsellorUser))->toBe(['viewer' => false, 'client' => false])
        ->and($group->fresh()->pauseStateFor($counsellorUser))->toBe(['viewer' => false, 'client' => null]);

    // The gate lets them write in it, as the page says.
    $this->actingAs($counsellorUser)->postJson(route('api.messages.create'), [
        'content' => 'Hello', 'type' => 'NORMAL', 'fromId' => $counsellor->id, 'fromType' => 'Counsellor', 'forId' => $f['session']->id, 'forType' => 'Session',
    ])->assertSuccessful();
});

test('pauseStateFor: the paused minor themselves, their 1:1 counsellor, and a guest', function () {
    $f = gpfClient(now()->subYears(15)->toDateString());
    $therapy = $f['therapy']->fresh();

    expect($therapy->pauseStateFor($f['client']))->toBe(['viewer' => true, 'client' => null])
        ->and($therapy->pauseStateFor($f['counsellorUser']))->toBe(['viewer' => false, 'client' => true])
        ->and($therapy->pauseStateFor($f['counsellorUser'], counterparty: false))->toBe(['viewer' => false, 'client' => null])
        ->and($therapy->pauseStateFor(null))->toBe(['viewer' => false, 'client' => null]);
});

// --- 6. The loss handling is queued -----------------------------------------------------------

test('a guardian going queues the loss handling once (after commit) and does no outbound work in the request', function () {
    Queue::fake([HandleGuardianLossJob::class]);
    $provider = gpfVideoProvider();
    app()->instance(VideoProviderInterface::class, $provider);
    $f = gpfMinorWithGuardian();
    $room = gpfInCall($f['session'], $f['client'], $f['counsellorUser']);

    gpfRemoveGuardianship($f['guardian'], $f['client']);

    Queue::assertPushed(HandleGuardianLossJob::class, 1);
    Notification::assertNotSentTo($f['client'], GuardianAccessPausedNotification::class);
    Notification::assertNotSentTo($f['counsellorUser'], ClientAccessPausedNotification::class);
    Event::assertNotDispatched(GuardianAccessChangedEvent::class);
    expect($room->fresh()->ended_at)->toBeNull()->and($provider->ended)->toBe([]);

    (new HandleGuardianLossJob($f['client']->id, RemoveGuardianFromWardAction::GUARDIANSHIP_REMOVED))->handle();

    expect($room->fresh()->ended_at)->not->toBeNull();
    Notification::assertSentToTimes($f['client'], GuardianAccessPausedNotification::class, 1);
    Notification::assertSentToTimes($f['counsellorUser'], ClientAccessPausedNotification::class, 1);
});

test('the queued loss handling does nothing when the ward has a guardian again by the time it runs', function () {
    Queue::fake([HandleGuardianLossJob::class]);
    $f = gpfMinorWithGuardian();
    gpfRemoveGuardianship($f['guardian'], $f['client']);
    Guardianship::query()->create(['guardian_id' => User::factory()->adult()->create()->id, 'ward_id' => $f['client']->id]);

    (new HandleGuardianLossJob($f['client']->id, RemoveGuardianFromWardAction::GUARDIANSHIP_REMOVED))->handle();

    Notification::assertNotSentTo($f['client'], GuardianAccessPausedNotification::class);
    Event::assertNotDispatched(GuardianAccessChangedEvent::class);
});

test('a guardian suspended with several wards queues one job per ward newly paused', function () {
    Queue::fake([HandleGuardianLossJob::class]);
    $guardian = User::factory()->adult()->create();
    $wards = collect(range(1, 3))->map(fn () => User::factory()->create(['dob' => now()->subYears(12)->toDateString()]));
    $wards->each(fn (User $ward) => Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $ward->id]));
    // This one has a second guardian, so isn't paused.
    Guardianship::query()->create(['guardian_id' => User::factory()->adult()->create()->id, 'ward_id' => $wards[2]->id]);

    gpfSuspend($guardian);

    Queue::assertPushed(HandleGuardianLossJob::class, 2);
});

// --- 7. Lock order ----------------------------------------------------------------------------

test('lifting a suspension locks the person before the suspension, as applying one does', function () {
    $person = User::factory()->adult()->create();
    $suspension = gpfSuspend($person);
    $queries = [];

    DB::transaction(function () use ($suspension, &$queries) {
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        LiftSuspensionAction::new()->lift(gpfAdmin(), $suspension, 'Lifted after a second look.', ReportStatusHistory::EVENT_SUSPENSION_LIFTED);
    });

    $personLock = collect($queries)->search(fn (string $sql) => str_starts_with($sql, 'select "id" from "users" where "users"."id" = ?'));
    $suspensionLock = collect($queries)->search(fn (string $sql) => str_starts_with($sql, 'select * from "suspensions" where "suspensions"."id" = ?'));

    expect($personLock)->not->toBeFalse()
        ->and($suspensionLock)->not->toBeFalse()
        ->and($personLock)->toBeLessThan($suspensionLock);
});

// --- 8. Tidy-ups ------------------------------------------------------------------------------

test('FailureResponse::withCode adds the code only for a refusal that has one', function () {
    expect(FailureResponse::withCode(['error' => 'x'], GuardianRequiredException::forViewer()))->toBe(['error' => 'x', 'code' => GuardianRequiredException::CODE])
        ->and(FailureResponse::withCode(['error' => 'x'], new RuntimeException('boom')))->toBe(['error' => 'x']);
});

test('the six tagged file slots each read and write only their own tag', function () {
    $user = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $user->id]);
    $organization = Organization::factory()->create();
    $request = Request::factory()->create(['type' => RequestTypeEnum::ageVerification->value, 'status' => RequestStatusEnum::pending->value, 'data' => []]);
    $step = HowToStep::factory()->create(['user_id' => $user->id]);
    $file = File::factory()->create();

    foreach ([
        [$user->avatarFile(), 'avatar'], [$counsellor->avatarFile(), 'avatar'], [$counsellor->coverFile(), 'cover'],
        [$organization->logoFile(), 'logo'], [$request->identityDocument(), 'identity-document'], [$step->stepFile(), 'step'],
    ] as [$relation, $tag]) {
        $relation->attach($file->id);
        expect(DB::table('fileables')->where('fileable_type', $relation->getParent()::class)->where('fileable_id', $relation->getParent()->id)->where('tag', $tag)->count())->toBe(1)
            ->and($relation->count())->toBe(1);
    }

    // Counsellor's avatar and cover share the pivot; each reads only its own row.
    expect($counsellor->fresh()->avatarFile()->count())->toBe(1)
        ->and($counsellor->fresh()->coverFile()->count())->toBe(1);
    DB::table('fileables')->where('fileable_type', Counsellor::class)->where('tag', 'cover')->delete();
    expect($counsellor->fresh()->avatarFile()->count())->toBe(1)
        ->and($counsellor->fresh()->coverFile()->count())->toBe(0);
});

test('the seed leaves no_dob_demo_user without a date of birth and gives other demo adults one', function () {
    $seeder = new DatabaseSeeder;
    $noDob = User::factory()->create(['username' => DatabaseSeeder::NO_DOB_DEMO_USERNAME, 'dob' => null]);
    $other = User::factory()->create(['dob' => null]);

    (fn () => $this->giveDemoAdultsADateOfBirth())->call($seeder);

    expect($noDob->fresh()->dob)->toBeNull()
        ->and($other->fresh()->isAdult())->toBeTrue();
});

// --- SCRUM-382 review follow-ups -------------------------------------------------------------

// How many database transactions $run opens. HandleGuardianLossAction::around() is one, and the
// only one on these edit paths -- the row lock itself can't be seen on SQLite (no FOR UPDATE).
function gpfTransactionsOpened(Closure $run): int
{
    $opened = 0;
    DB::connection()->getEventDispatcher()->listen(TransactionBeginning::class, function () use (&$opened) {
        $opened++;
    });
    $run();

    return $opened;
}

test('the backfill gives back the marker of an account it couldn\'t queue, so a re-run queues it', function () {
    $paused = gpfClient(now()->subYears(15)->toDateString());
    config(['queue.connections.gpf-broken' => ['driver' => 'gpf-no-such-driver'], 'queue.default' => 'gpf-broken']);

    expect(Artisan::call('guardians:announce-existing-pauses'))->toBe(1)
        ->and(Artisan::output())->toContain("Could not queue 1 account(s): {$paused['client']->id}")
        // The marker was given back, so it can be claimed again.
        ->and(SchedulerOnce::key("guardian-pause-existing:{$paused['client']->id}", now()->addMinute()))->toBeTrue();
    SchedulerOnce::forget("guardian-pause-existing:{$paused['client']->id}");

    config(['queue.default' => 'sync']);
    Queue::fake([HandleGuardianLossJob::class]);

    expect(Artisan::call('guardians:announce-existing-pauses'))->toBe(0)
        ->and(Artisan::output())->toContain("Queued the pause announcement for 1 account(s): {$paused['client']->id}");
    Queue::assertPushed(HandleGuardianLossJob::class, 1);

    expect(Artisan::call('guardians:announce-existing-pauses'))->toBe(0)
        ->and(Artisan::output())->toContain('Skipped 1 already announced.');
    Queue::assertPushed(HandleGuardianLossJob::class, 1);
});

test('a co-admin of a paused minor\'s group can\'t create a link into it', function () {
    [$creator, $group] = gpfPausedGroupCreator();
    $coAdminUser = User::factory()->adult()->create();
    $coAdmin = Counsellor::factory()->create(['user_id' => $coAdminUser->id]);
    $group->counsellors()->attach($coAdmin->id, ['state' => 'ACTIVE', 'role' => 'ADMIN']);

    foreach (EnsureUserCanCreateLinkForAction::GROUP_TYPES as $type) {
        foreach ([['User', $coAdminUser->id], ['Counsellor', $coAdmin->id]] as [$addedbyType, $addedbyId]) {
            $this->actingAs($coAdminUser)->postJson(route('api.links.create'), [
                'type' => $type, 'addedbyType' => $addedbyType, 'addedbyId' => $addedbyId, 'forType' => 'GroupTherapy', 'forId' => $group->id,
            ])->assertForbidden();
        }
    }

    expect(Link::query()->where('for_type', GroupTherapy::class)->where('for_id', $group->id)->exists())->toBeFalse();
});

test('a self edit that leaves the dob alone saves without the lock-and-check transaction; one that changes it uses it', function () {
    // The visit log (StoreVisitationMiddleware) opens its own transaction on every request.
    Queue::fake([HandleGuardianLossJob::class, StoreVisitationJob::class]);
    $user = User::factory()->adult()->create(['email_verified_at' => now(), 'dob' => now()->subYears(30)->toDateString()]);
    $payload = ['firstName' => 'Renamed', 'lastName' => $user->lastName, 'email' => $user->email, 'dob' => $user->dob->toDateString()];

    expect(gpfTransactionsOpened(fn () => $this->actingAs($user)->patch(route('profile.update'), $payload)->assertSessionHasNoErrors()))->toBe(0)
        ->and($user->fresh()->firstName)->toBe('Renamed');

    expect(gpfTransactionsOpened(fn () => $this->actingAs($user->fresh())->patch(route('profile.update'), [...$payload, 'dob' => now()->subYears(31)->toDateString()])->assertSessionHasNoErrors()))->toBe(1);
    Queue::assertNotPushed(HandleGuardianLossJob::class);
});

test('an administrator\'s edit that leaves the dob alone saves without the lock-and-check transaction', function () {
    $user = User::factory()->adult()->create(['dob' => now()->subYears(30)->toDateString()]);
    $dto = UpdateUserDTO::new()->fromArray(['user' => gpfAdmin(), 'updatedUser' => $user, 'firstName' => 'Edited', 'lastName' => $user->lastName, 'email' => $user->email]);

    expect(gpfTransactionsOpened(fn () => UpdateUserAction::new()->execute($dto)))->toBe(0)
        ->and($user->fresh()->firstName)->toBe('Edited');
});
