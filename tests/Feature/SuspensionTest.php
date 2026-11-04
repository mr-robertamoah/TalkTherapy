<?php

use App\Actions\Payout\GetCounsellorPayoutOverviewAction;
use App\Actions\Payout\TriggerCounsellorPayoutAction;
use App\Actions\Request\CreateRequestAction;
use App\Actions\Suspension\ApplySuspensionAction;
use App\Actions\Suspension\ApplySuspensionEffectsAction;
use App\Actions\Suspension\EnsureCanActAsCounsellorAction;
use App\Actions\Suspension\EnsureCanApplySuspensionAction;
use App\Actions\Suspension\EnsureCanDeleteOwnAccountWhileSuspendedAction;
use App\Actions\Suspension\EnsureTherapyCounsellorIsAvailableAction;
use App\Actions\Suspension\SubmitSuspensionAppealAction;
use App\Contracts\VideoProviderInterface;
use App\DTOs\CreatePostDTO;
use App\DTOs\CreateRequestDTO;
use App\DTOs\CreateTherapyTopicDTO;
use App\DTOs\TriggerPayoutDTO;
use App\DTOs\UpdateUserDTO;
use App\Enums\ReportStatusEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\SessionStatusEnum;
use App\Enums\SuspensionScopeEnum;
use App\Events\AccountSuspendedEvent;
use App\Events\SessionUpdatedEvent;
use App\Exceptions\PayoutException;
use App\Exceptions\SuspensionException;
use App\Jobs\ProcessCounsellorPayoutJob;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\CounsellorPayout;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\Report;
use App\Models\ReportStatusHistory;
use App\Models\Request;
use App\Models\Session;
use App\Models\Suspension;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoSession;
use App\Models\VideoSessionParticipant;
use App\Notifications\CounsellorUnavailableNotification;
use App\Notifications\SuspensionAppealDecidedNotification;
use App\Notifications\SuspensionAppliedNotification;
use App\Notifications\SuspensionEndedNotification;
use App\Notifications\WardSuspendedNotification;
use App\Services\PostService;
use App\Services\SuspensionService;
use App\Services\TherapyTopicService;
use App\Services\UserService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

// SCRUM-359: suspending a person from a resolved report -- who may, what it blocks, what it does
// to their clients, how it ends, and the one appeal.

function suspSuperAdmin(): User
{
    return User::factory()->adult()->has(Administrator::factory())->create();
}

function suspNormalAdmin(): User
{
    return User::factory()->adult()->has(Administrator::factory()->state(['type' => 'NORMAL']))->create();
}

// A client's individual therapy with a counsellor, and the client's report about the counsellor,
// resolved with "suspension recommended".
function suspCounsellorCase(array $reportAttributes = []): array
{
    $client = User::factory()->adult()->create();
    $counsellorUser = User::factory()->adult()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id]);

    $report = new Report(['description' => 'The counsellor was abusive', 'data' => ['counsellorId' => $counsellor->id]]);
    $report->addedby()->associate($client);
    $report->reportable()->associate($therapy);
    $report->save();
    $report->forceFill(array_merge(['status' => 'resolved', 'outcome' => 'suspension_recommended', 'resolution_note' => 'Serious'], $reportAttributes))->save();

    return compact('client', 'counsellorUser', 'counsellor', 'therapy', 'report');
}

function suspApply(User $admin, Report $report, string $scope = 'account', ?int $days = 30): Suspension
{
    return ApplySuspensionAction::new()->execute($admin, $report, SuspensionScopeEnum::from($scope), $days, 'Repeated abusive messages to a client.', 'Internal: see report');
}

function suspPendingSession(Therapy|GroupTherapy $therapy, int $startsInDays): Session
{
    return Session::factory()->create([
        'for_id' => $therapy->id,
        'for_type' => $therapy::class,
        'status' => SessionStatusEnum::pending->value,
        'start_time' => now()->addDays($startsInDays),
        'end_time' => now()->addDays($startsInDays)->addHour(),
    ]);
}

beforeEach(function () {
    Notification::fake();
    Event::fake([AccountSuspendedEvent::class]);
});

test('a super admin suspends the reported person from a resolved report, and it is recorded', function () {
    $c = suspCounsellorCase();
    $admin = suspSuperAdmin();

    $this->actingAs($admin)->post(route('admin.suspensions.apply', ['report' => $c['report']->id]), [
        'scope' => 'account', 'duration' => '30', 'public_reason' => 'Repeated abusive messages to a client.', 'internal_note' => 'see report',
    ])->assertSessionHasNoErrors();

    $suspension = Suspension::sole();
    expect($suspension->user_id)->toBe($c['counsellorUser']->id)
        ->and($suspension->report_id)->toBe($c['report']->id)
        ->and($suspension->scope)->toBe(SuspensionScopeEnum::account)
        ->and($suspension->ends_at->diffInDays(now()->addDays(30)) < 1)->toBeTrue()
        ->and($suspension->created_by)->toBe($admin->id)
        ->and($c['counsellorUser']->fresh()->isAccountSuspended())->toBeTrue()
        ->and(ReportStatusHistory::where('report_id', $c['report']->id)->where('event', 'suspension_applied')->exists())->toBeTrue();

    Notification::assertSentTo($c['counsellorUser'], SuspensionAppliedNotification::class, function ($n, $channels, $notifiable) use ($admin, $c) {
        $mail = implode(' ', $n->toMail($notifiable)->introLines);

        return str_contains($mail, 'Repeated abusive messages') && ! str_contains($mail, $admin->name)
            && ! str_contains($mail, $c['client']->name) && ! str_contains($mail, 'Internal');
    });
    Event::assertDispatched(AccountSuspendedEvent::class);
});

test('only an uninvolved super admin can suspend, and only from a report resolved with suspension recommended', function () {
    $c = suspCounsellorCase();
    $apply = fn (User $admin, ?Report $report = null, string $scope = 'account') => $this->actingAs($admin)
        ->post(route('admin.suspensions.apply', ['report' => ($report ?? $c['report'])->id]), ['scope' => $scope, 'duration' => '7', 'public_reason' => 'Repeated abusive messages.']);

    $apply(suspNormalAdmin())->assertSessionHasErrors(['alert' => EnsureCanApplySuspensionAction::SUPER_ADMIN_ONLY]);
    $apply(User::factory()->create())->assertSessionHasErrors('alert');
    $apply($c['counsellorUser'])->assertSessionHasErrors('alert');

    $warning = suspCounsellorCase(['outcome' => 'warning']);
    $apply(suspSuperAdmin(), $warning['report'])->assertSessionHasErrors('alert');
    $open = suspCounsellorCase(['status' => 'under_review', 'outcome' => null]);
    $apply(suspSuperAdmin(), $open['report'])->assertSessionHasErrors('alert');
    $nobody = suspCounsellorCase();
    $nobody['report']->forceFill(['data' => []])->save();
    $apply(suspSuperAdmin(), $nobody['report'])->assertSessionHasErrors('alert');

    $superTarget = suspCounsellorCase();
    Administrator::factory()->create(['user_id' => $superTarget['counsellorUser']->id]);
    $apply(suspSuperAdmin(), $superTarget['report'])->assertSessionHasErrors(['alert' => 'A super administrator cannot be suspended.']);

    $admin = suspSuperAdmin();
    $apply($admin)->assertSessionHasNoErrors();
    $apply($admin)->assertSessionHasErrors(['alert' => 'This person is already suspended.']);
    $apply($admin, null, 'counsellor')->assertSessionHasErrors(['alert' => 'This person is already suspended.']);

    $apply($admin, $c['report'], 'nonsense')->assertSessionHasErrors('scope');
    expect(Suspension::count())->toBe(1);
});

test('an account suspension blocks every page and API call except the suspended page and signing out', function () {
    $c = suspCounsellorCase();
    suspApply(suspSuperAdmin(), $c['report']);
    $person = $c['counsellorUser'];

    $this->actingAs($person)->get(route('home'))->assertRedirect(route('suspended.show'));
    $this->actingAs($person)->getJson(route('api.counsellors'))->assertForbidden()->assertJsonPath('code', 'account_suspended');
    $this->actingAs($person)->postJson('/broadcasting/auth', ['channel_name' => "private-users.{$person->id}", 'socket_id' => '1.1'])->assertForbidden();

    $this->actingAs($person)->get(route('suspended.show'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Suspended')
            ->where('suspension.reason', 'Repeated abusive messages to a client.')
            ->where('suspension.canAppeal', true)
            ->missing('suspension.internalNote'));
    $this->actingAs($person)->post(route('logout'))->assertRedirect();

    // Once it has run out, nothing is blocked -- whether or not the sweep has run.
    $this->travel(31)->days();
    $this->actingAs($person->fresh())->getJson(route('api.counsellors'))->assertOk();
    $this->actingAs($person->fresh())->get(route('suspended.show'))->assertRedirect(route('home'));
});

test('a suspended admin loses the admin pages too', function () {
    $c = suspCounsellorCase();
    Administrator::factory()->create(['user_id' => $c['counsellorUser']->id, 'type' => 'NORMAL']);
    suspApply(suspSuperAdmin(), $c['report']);

    $this->actingAs($c['counsellorUser'])->get(route('administrator.reports'))->assertRedirect(route('suspended.show'));
});

test('a counsellor-role suspension hides them and stops them working, but they can still be a client', function () {
    $c = suspCounsellorCase();
    suspApply(suspSuperAdmin(), $c['report'], 'counsellor');
    $person = $c['counsellorUser'];
    $viewer = User::factory()->adult()->create();

    $this->actingAs($viewer)->getJson(route('api.counsellors'))->assertOk()
        ->assertJsonMissing(['id' => $c['counsellor']->id]);
    expect(Counsellor::query()->whereNotSuspended()->whereKey($c['counsellor']->id)->exists())->toBeFalse();

    // Not blocked from the site.
    $this->actingAs($person)->get(route('suspended.show'))->assertOk();
    $this->actingAs($person)->getJson(route('api.counsellors'))->assertOk();

    // Can't write as a counsellor...
    $session = Session::factory()->create(['for_id' => $c['therapy']->id, 'for_type' => Therapy::class, 'status' => SessionStatusEnum::in_session->value, 'addedby_type' => Counsellor::class, 'addedby_id' => $c['counsellor']->id]);
    $this->actingAs($person)->postJson(route('api.messages.create'), [
        'content' => 'hello', 'type' => 'NORMAL', 'fromId' => $c['counsellor']->id, 'fromType' => 'Counsellor', 'forId' => $session->id, 'forType' => 'Session',
    ])->assertForbidden();

    // ...can't take on work, and can't be asked to...
    $request = Request::factory()->create([
        'type' => RequestTypeEnum::therapy->value, 'status' => 'PENDING', 'data' => [],
        'from_type' => User::class, 'from_id' => User::factory()->create()->id,
        'to_type' => Counsellor::class, 'to_id' => $c['counsellor']->id,
        'for_type' => Therapy::class, 'for_id' => Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()->adult()->create()->id])->id,
    ]);
    $this->actingAs($person)->postJson(route('requests.respond', ['requestId' => $request->id]), ['response' => 'accepted'])
        ->assertForbidden()->assertJsonPath('error', EnsureCanActAsCounsellorAction::SUSPENDED);

    // ...nor can their client keep the sessions going with them.
    $this->actingAs($c['client'])->postJson(route('api.sessions.in_session', ['sessionId' => $session->id]))->assertStatus(403);

    expect($person->fresh()->isAccountSuspended())->toBeFalse()
        ->and($c['counsellor']->fresh()->isSuspended())->toBeTrue();
});

test('suspending a counsellor fails their upcoming sessions in the window, declines requests and tells clients neutrally', function () {
    $c = suspCounsellorCase();
    $inWindow = suspPendingSession($c['therapy'], 3);
    $afterWindow = suspPendingSession($c['therapy'], 10);
    $held = Session::factory()->create(['for_id' => $c['therapy']->id, 'for_type' => Therapy::class, 'status' => SessionStatusEnum::held->value, 'start_time' => now()->addDay(), 'end_time' => now()->addDay()->addHour()]);

    $requester = User::factory()->adult()->create();
    $request = Request::factory()->create([
        'type' => RequestTypeEnum::therapy->value, 'status' => 'PENDING', 'data' => [],
        'from_type' => User::class, 'from_id' => $requester->id,
        'to_type' => Counsellor::class, 'to_id' => $c['counsellor']->id,
        'for_type' => Therapy::class, 'for_id' => Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $requester->id])->id,
    ]);
    $compensation = Request::factory()->create([
        'type' => RequestTypeEnum::organizationCounsellorCompensationChange->value, 'status' => 'PENDING', 'data' => [],
        'from_type' => User::class, 'from_id' => User::factory()->create()->id,
        'to_type' => Counsellor::class, 'to_id' => $c['counsellor']->id,
    ]);

    // A group with a second counsellor carries on.
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()->adult()->create()->id]);
    $group->counsellors()->attach($c['counsellor']->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $group->counsellors()->attach(Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id])->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $groupSession = suspPendingSession($group, 2);

    suspApply(suspSuperAdmin(), $c['report'], 'counsellor', 7);

    expect($inWindow->fresh()->status)->toBe(SessionStatusEnum::failed->value)
        ->and($inWindow->fresh()->ended_at)->not->toBeNull()
        ->and($afterWindow->fresh()->status)->toBe(SessionStatusEnum::pending->value)
        ->and($held->fresh()->status)->toBe(SessionStatusEnum::held->value)
        ->and($groupSession->fresh()->status)->toBe(SessionStatusEnum::pending->value)
        ->and($request->fresh()->status)->toBe(RequestStatusEnum::rejected->value)
        ->and($request->fresh()->data['reason'])->toBe(ApplySuspensionEffectsAction::DECLINED_REASON)
        ->and($request->fresh()->responded_at)->not->toBeNull()
        ->and($compensation->fresh()->status)->toBe(RequestStatusEnum::pending->value)
        ->and($c['therapy']->fresh()->status)->not->toBe('ENDED');

    Notification::assertSentTo($c['client'], CounsellorUnavailableNotification::class, function ($n, $channels, $notifiable) {
        $mail = $n->toMail($notifiable);
        $text = strtolower(implode(' ', [...$mail->introLines, ...$mail->outroLines, $mail->subject]));

        return $n->toArray($notifiable)['cancelledSessions'] === 1 && ! str_contains($text, 'suspend') && str_contains($text, 'refund');
    });
    Notification::assertSentTo($requester, CounsellorUnavailableNotification::class, fn ($n, $channels, $notifiable) => $n->toArray($notifiable)['requestDeclined'] === true);
});

test('a group with no other counsellor has its sessions in the window failed', function () {
    $c = suspCounsellorCase();
    $member = User::factory()->adult()->create();
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()->adult()->create()->id]);
    $group->counsellors()->attach($c['counsellor']->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $group->users()->attach($member->id, ['anonymous' => true]);
    $session = suspPendingSession($group, 2);

    suspApply(suspSuperAdmin(), $c['report'], 'counsellor', null);

    expect($session->fresh()->status)->toBe(SessionStatusEnum::failed->value);
    Notification::assertSentTo($member, CounsellorUnavailableNotification::class);
});

test('a suspension that reaches its end stops applying, and the sweep tells the person once', function () {
    $c = suspCounsellorCase();
    $suspension = suspApply(suspSuperAdmin(), $c['report'], 'account', 7);

    expect(SuspensionService::new()->endExpiredSuspensions())->toBe(0);

    $this->travel(8)->days();
    expect($c['counsellorUser']->fresh()->isAccountSuspended())->toBeFalse()
        ->and(SuspensionService::new()->endExpiredSuspensions())->toBe(1)
        ->and(SuspensionService::new()->endExpiredSuspensions())->toBe(0)
        ->and($suspension->fresh()->end_processed_at)->not->toBeNull()
        ->and(ReportStatusHistory::where('event', 'suspension_ended')->count())->toBe(1);

    Notification::assertSentToTimes($c['counsellorUser'], SuspensionEndedNotification::class, 1);
});

test('lifting early needs a super admin and a reason, and is recorded', function () {
    $c = suspCounsellorCase();
    $suspension = suspApply(suspSuperAdmin(), $c['report'], 'account', null);
    $lift = fn (User $admin, array $body) => $this->actingAs($admin)->post(route('admin.suspensions.lift', ['suspension' => $suspension->id]), $body);

    $lift(suspNormalAdmin(), ['reason' => 'Mistaken identity on review'])->assertSessionHasErrors('alert');
    $admin = suspSuperAdmin();
    $lift($admin, ['reason' => 'short'])->assertSessionHasErrors('alert');
    $lift($admin, ['reason' => 'Mistaken identity on review'])->assertSessionHasNoErrors();
    $lift($admin, ['reason' => 'Mistaken identity on review'])->assertSessionHasErrors(['alert' => 'This suspension has already ended.']);

    expect($suspension->fresh()->lifted_by)->toBe($admin->id)
        ->and($c['counsellorUser']->fresh()->isAccountSuspended())->toBeFalse()
        ->and(ReportStatusHistory::where('event', 'suspension_lifted')->value('note'))->toBe('Mistaken identity on review');
    Notification::assertSentTo($c['counsellorUser'], SuspensionEndedNotification::class);
});

test('the person can appeal once; another super admin decides, never the one who applied it', function () {
    $c = suspCounsellorCase();
    $applier = suspSuperAdmin();
    $suspension = suspApply($applier, $c['report'], 'account', 90);
    $appeal = fn (array $body) => $this->actingAs($c['counsellorUser'])->post(route('suspended.appeal', ['suspension' => $suspension->id]), $body);

    $appeal(['appeal' => 'too short'])->assertSessionHasErrors('appeal');
    $appeal(['appeal' => 'I was quoting the client back to them, please read the whole session.'])->assertSessionHasNoErrors();
    $appeal(['appeal' => 'Another appeal with plenty of words in it.'])->assertSessionHasErrors(['appeal' => 'You have already appealed this suspension.']);

    // Someone else's suspension can't be appealed.
    $this->actingAs($c['client'])->post(route('suspended.appeal', ['suspension' => $suspension->id]), ['appeal' => 'I was quoting the client back to them.'])
        ->assertSessionHasErrors(['appeal' => SubmitSuspensionAppealAction::NOT_FOUND]);

    $decide = fn (User $admin, string $decision) => $this->actingAs($admin)->post(route('admin.suspensions.appeal', ['suspension' => $suspension->id]), ['decision' => $decision, 'note' => 'Read the whole session again.']);
    $decide($applier, 'lifted')->assertSessionHasErrors('alert');
    $decide(suspSuperAdmin(), 'lifted')->assertSessionHasNoErrors();

    $suspension->refresh();
    expect($suspension->appeal_decision->value)->toBe('lifted')
        ->and($suspension->lifted_at)->not->toBeNull()
        ->and($c['counsellorUser']->fresh()->isAccountSuspended())->toBeFalse()
        ->and(ReportStatusHistory::where('event', 'appeal_submitted')->count())->toBe(1)
        ->and(ReportStatusHistory::where('event', 'appeal_decided')->count())->toBe(1);
    Notification::assertSentTo($c['counsellorUser'], SuspensionAppealDecidedNotification::class);
});

test('an upheld appeal leaves the suspension in force', function () {
    $c = suspCounsellorCase();
    $suspension = suspApply(suspSuperAdmin(), $c['report'], 'counsellor', 30);
    $this->actingAs($c['counsellorUser'])->post(route('suspended.appeal', ['suspension' => $suspension->id]), ['appeal' => 'Please look again at what happened in the session.']);

    $this->actingAs(suspSuperAdmin())->post(route('admin.suspensions.appeal', ['suspension' => $suspension->id]), ['decision' => 'upheld', 'note' => 'The messages were clearly abusive.'])
        ->assertSessionHasNoErrors();

    expect($suspension->fresh()->isActive())->toBeTrue()
        ->and($suspension->fresh()->appeal_decision->value)->toBe('upheld');
});

test('guardians of a suspended minor are told', function () {
    $c = suspCounsellorCase();
    $c['counsellorUser']->forceFill(['dob' => now()->subYears(16)->toDateString()])->save();
    $guardian = User::factory()->adult()->create();
    Guardianship::create(['guardian_id' => $guardian->id, 'ward_id' => $c['counsellorUser']->id]);

    suspApply(suspSuperAdmin(), $c['report'], 'account', 7);

    Notification::assertSentTo($guardian, WardSuspendedNotification::class);
});

test('the report can not be re-pointed while its suspension is in force, and reopening keeps it', function () {
    $c = suspCounsellorCase();
    $admin = suspSuperAdmin();
    $suspension = suspApply($admin, $c['report'], 'account', 30);

    $this->actingAs($admin)->post(route('admin.reports.status', ['report' => $c['report']->id]), ['status' => 'under_review'])->assertSessionHasNoErrors();
    expect($c['report']->fresh()->status)->toBe(ReportStatusEnum::under_review)
        ->and($suspension->fresh()->isActive())->toBeTrue();

    $this->actingAs($admin)->post(route('admin.reports.party', ['report' => $c['report']->id]), ['type' => 'user', 'id' => $c['client']->id])
        ->assertSessionHasErrors('alert');
});

test('the report page and the suspensions list show the suspension; an involved admin sees only that it exists', function () {
    $c = suspCounsellorCase();
    $admin = suspSuperAdmin();
    suspApply($admin, $c['report'], 'counsellor', 30);

    $this->actingAs($admin)->get(route('administrator.reports.show', ['report' => $c['report']->id]))->assertOk()
        ->assertInertia(fn ($page) => $page->where('report.suspensions.0.scope', 'counsellor')
            ->where('report.suspensions.0.status', 'active')
            ->where('report.suspend.scopes', ['account']));

    $this->actingAs(suspNormalAdmin())->get(route('administrator.suspensions'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Suspensions')
            ->where('suspensions.data.0.publicReason', 'Repeated abusive messages to a client.')
            ->where('suspensions.data.0.canLift', false));

    $involved = suspSuperAdmin();
    Guardianship::create(['guardian_id' => $involved->id, 'ward_id' => $c['client']->id]);
    $this->actingAs($involved)->get(route('administrator.suspensions'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('suspensions.data.0.involved', true)->missing('suspensions.data.0.publicReason'));
});

// Review findings ---------------------------------------------------------------------------------

test('a suspended counsellor can not write in a running session by sending as their personal account, and running sessions end', function () {
    $c = suspCounsellorCase();
    $running = Session::factory()->create(['for_id' => $c['therapy']->id, 'for_type' => Therapy::class, 'status' => SessionStatusEnum::in_session->value, 'start_time' => now()->subMinutes(10), 'end_time' => now()->addMinutes(50)]);
    suspApply(suspSuperAdmin(), $c['report'], 'counsellor', 7);

    expect($running->fresh()->status)->toBe(SessionStatusEnum::abandoned->value);

    $running->forceFill(['status' => SessionStatusEnum::in_session->value])->save();
    $this->actingAs($c['counsellorUser'])->postJson(route('api.messages.create'), [
        'content' => 'hello', 'type' => 'NORMAL', 'fromId' => $c['counsellorUser']->id, 'fromType' => 'User', 'forId' => $running->id, 'forType' => 'Session',
    ])->assertForbidden();

    // Only the writer's own role is checked for messages -- their client isn't refused for it.
    EnsureTherapyCounsellorIsAvailableAction::new()->execute($c['client'], $c['therapy'], includeTarget: false);
    expect(fn () => EnsureTherapyCounsellorIsAvailableAction::new()->execute($c['client'], $c['therapy']))
        ->toThrow(SuspensionException::class, EnsureCanActAsCounsellorAction::UNAVAILABLE);
});

test('a suspended counsellor can not publish a post as their counsellor profile', function () {
    $c = suspCounsellorCase();
    suspApply(suspSuperAdmin(), $c['report'], 'counsellor', 7);

    expect(fn () => PostService::new()->createPost(CreatePostDTO::new()->fromArray([
        'user' => $c['counsellorUser'], 'addedby' => $c['counsellor'], 'content' => 'A post',
    ])))->toThrow(SuspensionException::class, EnsureCanActAsCounsellorAction::SUSPENDED);
});

test('requests to or from a suspended counsellor can not be created, and their pending offers are withdrawn', function () {
    $c = suspCounsellorCase();
    $application = Request::factory()->create([
        'type' => RequestTypeEnum::organizationCounsellorApplication->value, 'status' => 'PENDING', 'data' => [],
        'from_type' => Counsellor::class, 'from_id' => $c['counsellor']->id,
        'to_type' => User::class, 'to_id' => User::factory()->create()->id,
    ]);
    suspApply(suspSuperAdmin(), $c['report'], 'counsellor', 7);

    expect($application->fresh()->status)->toBe(RequestStatusEnum::inconsequencial->value);

    $create = fn ($from, $to) => CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
        'from' => $from, 'to' => $to, 'for' => $c['therapy'], 'type' => RequestTypeEnum::therapy->value, 'data' => [],
    ]));
    expect(fn () => $create($c['client'], $c['counsellor']))->toThrow(SuspensionException::class, EnsureCanActAsCounsellorAction::UNAVAILABLE)
        ->and(fn () => $create($c['counsellor'], $c['client']))->toThrow(SuspensionException::class, EnsureCanActAsCounsellorAction::SUSPENDED);
});

test('an account suspension also stops API-token callers', function () {
    $c = suspCounsellorCase();
    suspApply(suspSuperAdmin(), $c['report']);
    Sanctum::actingAs($c['counsellorUser']);

    $this->getJson(route('api.counsellors'))->assertForbidden()->assertJsonPath('code', 'account_suspended');
});

test('the suspended person and their guardian are treated as involved even after the report is re-pointed', function () {
    $c = suspCounsellorCase();
    Administrator::factory()->create(['user_id' => $c['counsellorUser']->id, 'type' => 'NORMAL']);
    $suspension = suspApply(suspSuperAdmin(), $c['report'], 'counsellor', 7);
    $guardian = suspSuperAdmin();
    Guardianship::create(['guardian_id' => $guardian->id, 'ward_id' => $c['counsellorUser']->id]);
    $c['report']->forceFill(['data' => []])->save();

    expect($suspension->fresh()->involves($c['counsellorUser']))->toBeTrue()
        ->and($suspension->fresh()->involves($guardian))->toBeTrue();
    $this->actingAs($guardian)->post(route('admin.suspensions.lift', ['suspension' => $suspension->id]), ['reason' => 'Lifting my own ward early'])
        ->assertSessionHasErrors('alert');
    $this->actingAs($c['counsellorUser'])->get(route('administrator.suspensions'))
        ->assertInertia(fn ($page) => $page->where('suspensions.data.0.involved', true)->missing('suspensions.data.0.internalNote'));
});

test('lifting a suspension while an appeal waits answers the appeal', function () {
    $c = suspCounsellorCase();
    $suspension = suspApply(suspSuperAdmin(), $c['report'], 'account', 30);
    $this->actingAs($c['counsellorUser'])->post(route('suspended.appeal', ['suspension' => $suspension->id]), ['appeal' => 'Please look again at what happened in the session.']);

    $this->actingAs(suspSuperAdmin())->post(route('admin.suspensions.lift', ['suspension' => $suspension->id]), ['reason' => 'Reconsidered after the appeal'])
        ->assertSessionHasNoErrors();

    expect($suspension->fresh()->appeal_decision->value)->toBe('lifted')
        ->and($suspension->fresh()->appeal_note)->toBe('Reconsidered after the appeal');
});

// Every place a counsellor works or is asked for work keeps calling the suspension check -- a new
// counsellor entry point should be added here.
arch('counsellor entry points call the suspension check')
    ->expect([
        'App\Services\RequestService',
        'App\Actions\Request\CreateRequestAction',
        'App\Services\MessageService',
        'App\Services\TherapyService',
        'App\Services\GroupTherapyService',
        'App\Services\DiscussionService',
        'App\Services\PostService',
        'App\Actions\Link\PerformLinkAction',
        'App\Services\SessionNoteService',
        'App\Services\MessageNoteService',
    ])
    ->toUse('App\Actions\Suspension\EnsureCanActAsCounsellorAction');

// SCRUM-288: through EnsureTherapyCanProceedAction, which runs this check first.
arch('session entry points call the therapy-counsellor check')
    ->expect([
        'App\Services\SessionService',
        'App\Services\SessionScheduleProposalService',
        'App\Actions\Video\JoinVideoSessionAction',
        'App\Services\MessageService',
        'App\Services\TherapyTopicService',
    ])
    ->toUse('App\Actions\Therapy\EnsureTherapyCanProceedAction');

test('a normal admin is not offered the suspend form', function () {
    $c = suspCounsellorCase();

    $this->actingAs(suspNormalAdmin())->get(route('administrator.reports.show', ['report' => $c['report']->id]))->assertOk()
        ->assertInertia(fn ($page) => $page->where('report.suspend.available', false)->where('report.suspend.superAdminOnly', true));
});

test('a signed-out visitor can search counsellors, and suspended ones are left out', function () {
    $c = suspCounsellorCase();
    $other = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    suspApply(suspSuperAdmin(), $c['report'], 'counsellor', 7);

    $this->getJson(route('api.counsellors'))->assertOk()
        ->assertJsonFragment(['id' => $other->id])
        ->assertJsonMissing(['id' => $c['counsellor']->id]);
});

// SCRUM-363 ---------------------------------------------------------------------------------------

test('a suspended counsellor can not write session notes or change topics, and reading still works', function () {
    $c = suspCounsellorCase();
    suspApply(suspSuperAdmin(), $c['report'], 'counsellor', 7);
    // Notes need a running session; a suspension ends those, so one is started afterwards.
    $session = Session::factory()->create(['for_id' => $c['therapy']->id, 'for_type' => Therapy::class, 'status' => SessionStatusEnum::in_session->value, 'start_time' => now()->subMinutes(5), 'end_time' => now()->addHour()]);

    $this->actingAs($c['counsellorUser'])->postJson(route('api.session.notes.store', ['sessionId' => $session->id]), ['content' => 'A note'])
        ->assertForbidden();
    $this->actingAs($c['counsellorUser'])->getJson(route('api.session.notes.index', ['sessionId' => $session->id]))->assertOk();

    expect(fn () => TherapyTopicService::new()->createTherapyTopic(CreateTherapyTopicDTO::new()->fromArray([
        'user' => $c['counsellorUser'], 'therapy' => $c['therapy'], 'name' => 'Sleep', 'description' => 'About sleep',
    ])))->toThrow(SuspensionException::class);
});

test('a guardian of any member of a reported group is part of the report', function () {
    $member = User::factory()->adult()->create();
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()->adult()->create()->id]);
    $group->users()->attach($member->id, ['anonymous' => true]);
    $report = new Report(['description' => 'Something happened', 'data' => []]);
    $report->addedby()->associate(User::factory()->create());
    $report->reportable()->associate($group);
    $report->save();
    $guardian = suspSuperAdmin();
    Guardianship::create(['guardian_id' => $guardian->id, 'ward_id' => $member->id]);

    expect(Report::find($report->id)->involves($guardian))->toBeTrue()
        ->and(Report::find($report->id)->involves(suspSuperAdmin()))->toBeFalse();
});

test('sessions a suspension cancels are broadcast to open pages', function () {
    Event::fake([AccountSuspendedEvent::class, SessionUpdatedEvent::class]);
    $c = suspCounsellorCase();
    suspPendingSession($c['therapy'], 2);

    suspApply(suspSuperAdmin(), $c['report'], 'counsellor', 7);

    Event::assertDispatched(SessionUpdatedEvent::class, 1);
});

test('a suspended counsellor\'s payouts are held, whoever asks, and they are told', function () {
    $c = suspCounsellorCase();
    suspApply(suspSuperAdmin(), $c['report'], 'counsellor', 7);

    expect(fn () => TriggerCounsellorPayoutAction::new()->execute(TriggerPayoutDTO::new()->fromArray(['user' => $c['counsellorUser']])))
        ->toThrow(PayoutException::class, TriggerCounsellorPayoutAction::ON_HOLD);

    $overview = GetCounsellorPayoutOverviewAction::new()->execute($c['counsellor']->fresh());
    expect($overview['onHold'])->toBeTrue()->and($overview['onHoldUntil'])->not->toBeNull();

    Notification::assertSentTo($c['counsellorUser'], SuspensionAppliedNotification::class, fn ($n, $channels, $notifiable) => str_contains(implode(' ', $n->toMail($notifiable)->introLines), 'payouts are on hold'));

    $this->travel(8)->days();
    expect(GetCounsellorPayoutOverviewAction::new()->execute($c['counsellor']->fresh())['onHold'])->toBeFalse();
});

test('a suspended person can not delete their own account or counsellor profile', function () {
    $c = suspCounsellorCase();
    suspApply(suspSuperAdmin(), $c['report'], 'counsellor', 7);

    $this->actingAs($c['counsellorUser'])->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasErrors(['password' => EnsureCanDeleteOwnAccountWhileSuspendedAction::SUSPENDED]);
    $this->actingAs($c['counsellorUser'])->delete(route('counsellor.delete', ['counsellorId' => $c['counsellor']->id]), ['password' => 'password'])
        ->assertSessionHasErrors('alert');

    expect($c['counsellorUser']->fresh())->not->toBeNull()
        ->and($c['counsellor']->fresh())->not->toBeNull();
});

test('the privacy page is open to everyone, a suspended account included', function () {
    $this->get(route('privacy'))->assertOk()->assertInertia(fn ($page) => $page->component('Privacy'));

    $c = suspCounsellorCase();
    suspApply(suspSuperAdmin(), $c['report']);
    $this->actingAs($c['counsellorUser'])->get(route('privacy'))->assertOk();
});

function suspVideoProvider(array &$removed): VideoProviderInterface
{
    return new class($removed) implements VideoProviderInterface
    {
        public function __construct(private array &$removed) {}

        public function createRoom(VideoSession $videoSession): array
        {
            return [];
        }

        public function createParticipantCredentials(VideoSession $videoSession, User $user, string $displayName, bool $receiveOnly = false, bool $canSendVideo = true): array
        {
            return [];
        }

        public function endRoom(VideoSession $videoSession): void
        {
            $this->removed[] = "room:{$videoSession->id}";
        }

        public function removeParticipant(VideoSession $videoSession, User $user): void
        {
            $this->removed[] = "user:{$user->id}";
        }

        public function updateParticipantCapabilities(VideoSession $videoSession, User $user, bool $canSendAudio, bool $canSendVideo): void {}
    };
}

test('a suspended counsellor is taken out of a live group call that carries on with another counsellor', function () {
    $c = suspCounsellorCase();
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()->adult()->create()->id]);
    $group->counsellors()->attach($c['counsellor']->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $group->counsellors()->attach(Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id])->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $session = Session::factory()->create(['for_id' => $group->id, 'for_type' => GroupTherapy::class, 'status' => SessionStatusEnum::in_session->value, 'start_time' => now()->subMinutes(5), 'end_time' => now()->addHour()]);
    $videoSession = VideoSession::factory()->create(['session_id' => $session->id, 'provider_room_id' => 'room-g']);
    $row = VideoSessionParticipant::factory()->create(['video_session_id' => $videoSession->id, 'participant_type' => User::class, 'participant_id' => $c['counsellorUser']->id, 'left_at' => null]);
    $removed = [];
    app()->instance(VideoProviderInterface::class, suspVideoProvider($removed));

    suspApply(suspSuperAdmin(), $c['report'], 'counsellor', 7);

    expect($session->fresh()->status)->toBe(SessionStatusEnum::in_session->value)
        ->and($row->fresh()->left_at)->not->toBeNull()
        ->and($removed)->toContain("user:{$c['counsellorUser']->id}")
        ->and($videoSession->fresh()->ended_at)->toBeNull();
});

test('the video room of a session a suspension ends is closed', function () {
    $c = suspCounsellorCase();
    $session = Session::factory()->create(['for_id' => $c['therapy']->id, 'for_type' => Therapy::class, 'status' => SessionStatusEnum::in_session->value, 'start_time' => now()->subMinutes(5), 'end_time' => now()->addHour()]);
    $videoSession = VideoSession::factory()->create(['session_id' => $session->id, 'provider_room_id' => 'room-i']);
    $removed = [];
    app()->instance(VideoProviderInterface::class, suspVideoProvider($removed));

    suspApply(suspSuperAdmin(), $c['report'], 'counsellor', 7);

    expect($session->fresh()->status)->toBe(SessionStatusEnum::abandoned->value)
        ->and($videoSession->fresh()->ended_at)->not->toBeNull()
        ->and($removed)->toContain("room:{$videoSession->id}");
});

test('a video provider failing after the suspension is saved never stops the person being told', function () {
    $c = suspCounsellorCase();
    $session = Session::factory()->create(['for_id' => $c['therapy']->id, 'for_type' => Therapy::class, 'status' => SessionStatusEnum::in_session->value, 'start_time' => now()->subMinutes(5), 'end_time' => now()->addHour()]);
    VideoSession::factory()->create(['session_id' => $session->id, 'provider_room_id' => 'room-x']);
    $provider = Mockery::mock(VideoProviderInterface::class);
    $provider->shouldReceive('endRoom', 'removeParticipant')->andThrow(new RuntimeException('Daily API is unreachable.'));
    app()->instance(VideoProviderInterface::class, $provider);

    $suspension = suspApply(suspSuperAdmin(), $c['report'], 'account', 7);

    expect($suspension->exists)->toBeTrue();
    Notification::assertSentTo($c['counsellorUser'], SuspensionAppliedNotification::class);
});

test('a suspended counsellor can not set or unset a session topic', function () {
    $c = suspCounsellorCase();
    suspApply(suspSuperAdmin(), $c['report'], 'counsellor', 7);
    $session = Session::factory()->create(['for_id' => $c['therapy']->id, 'for_type' => Therapy::class, 'addedby_type' => Counsellor::class, 'addedby_id' => $c['counsellor']->id, 'status' => SessionStatusEnum::in_session->value, 'start_time' => now()->subMinutes(5), 'end_time' => now()->addHour()]);
    $topic = $c['therapy']->topics()->create(['name' => 'Sleep', 'description' => 'About sleep', 'counsellor_id' => $c['counsellor']->id]);

    foreach (['api.session.topic.set', 'api.session.topic.unset'] as $route) {
        $this->actingAs($c['counsellorUser'])->postJson(route($route, ['sessionId' => $session->id]), ['topicId' => $topic->id])
            ->assertStatus(403);
    }
});

test('a payout requested just before a suspension is held, and sent once it is lifted', function () {
    Queue::fake();
    $c = suspCounsellorCase();
    $payout = CounsellorPayout::factory()->create(['counsellor_id' => $c['counsellor']->id, 'status' => 'PENDING']);
    $suspension = suspApply(suspSuperAdmin(), $c['report'], 'counsellor', null);

    (new ProcessCounsellorPayoutJob($payout->id))->handle();
    expect($payout->fresh()->status)->toBe('PENDING');

    $this->actingAs(suspSuperAdmin())->post(route('admin.suspensions.lift', ['suspension' => $suspension->id]), ['reason' => 'Lifted after a second look'])
        ->assertSessionHasNoErrors();

    Queue::assertPushed(ProcessCounsellorPayoutJob::class);
});

test('an admin can not delete their own account through the admin panel while suspended', function () {
    $c = suspCounsellorCase();
    Administrator::factory()->create(['user_id' => $c['counsellorUser']->id]);
    // A super admin can't be suspended through the flow, so the suspension is recorded directly.
    Suspension::create(['user_id' => $c['counsellorUser']->id, 'report_id' => $c['report']->id, 'scope' => 'counsellor', 'starts_at' => now(), 'public_reason' => 'Seeded for the test.']);

    expect(fn () => UserService::new()->deleteUserByAdmin(UpdateUserDTO::new()->fromArray(['user' => $c['counsellorUser']->fresh(), 'updatedUser' => $c['counsellorUser']->fresh()])))
        ->toThrow(SuspensionException::class);
});
