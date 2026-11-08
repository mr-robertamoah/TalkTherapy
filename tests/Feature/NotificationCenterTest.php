<?php

use App\Contracts\SentOnlyToAdministrators;
use App\Contracts\SentOnlyToSuperAdministrators;
use App\Contracts\ShownInNotificationCenter;
use App\Enums\AdministratorTypeEnum;
use App\Events\NotificationsChangedEvent;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Report;
use App\Models\Request;
use App\Models\SafeguardingException;
use App\Models\Suspension;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\ClientAccessPausedNotification;
use App\Notifications\ClientAccessResumedNotification;
use App\Notifications\CounsellorUnavailableNotification;
use App\Notifications\CounsellorVerificationEndedNotification;
use App\Notifications\CounsellorVerificationEndingAdminNotification;
use App\Notifications\CounsellorVerificationEndingNotification;
use App\Notifications\CounsellorVerificationRevokedNotification;
use App\Notifications\GroupTherapyMemberLeftGuardianNotification;
use App\Notifications\GroupTherapyMemberLeftNotification;
use App\Notifications\GuardianAccessPausedNotification;
use App\Notifications\GuardianAccessResumedNotification;
use App\Notifications\GuardianSafetyNoticeNotification;
use App\Notifications\HelpRequestWaitingNotification;
use App\Notifications\HelpTherapyInviteNotification;
use App\Notifications\SafeguardingExceptionEndingSoonNotification;
use App\Notifications\SafeguardingExceptionRequestDecidedNotification;
use App\Notifications\SafeguardingExceptionRequestedNotification;
use App\Notifications\SafetyFlagRaisedNotification;
use App\Notifications\SafetyFlagReAlertNotification;
use App\Notifications\SafetyFlagWithdrawnNotification;
use App\Notifications\SafetyNoticeNotification;
use App\Notifications\SuspensionAppealDecidedNotification;
use App\Notifications\SuspensionAppealSubmittedNotification;
use App\Notifications\SuspensionAppliedNotification;
use App\Notifications\SuspensionEndedNotification;
use App\Notifications\TherapyCreatedNotification;
use App\Notifications\WardSuspendedNotification;
use App\Support\NotificationCenter;
use App\Support\WardRef;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

// SCRUM-39 (TT-5.4a): the notification center's JSON. Helpers are file-local and ncent-prefixed (--parallel).

function ncentInsert(User|Counsellor $notifiable, string $type, array $data = [], array $extra = []): string
{
    $id = (string) Str::uuid();
    DB::table('notifications')->insert([
        'id' => $id, 'type' => $type, 'notifiable_type' => $notifiable::class, 'notifiable_id' => $notifiable->id,
        'data' => json_encode($data), 'created_at' => now(), 'updated_at' => now(), ...$extra,
    ]);

    return $id;
}

function ncentAdmin(AdministratorTypeEnum $type = AdministratorTypeEnum::super): User
{
    return User::factory()->adult()->has(Administrator::factory()->state(['type' => $type->value]))->create();
}

test('every class stored in the database is worded for the center, or waits for TT-5.4b', function () {
    $missing = collect(glob(app_path('Notifications/*.php')))
        ->filter(fn (string $file) => str_contains(file_get_contents($file), "'database'"))
        ->map(fn (string $file) => 'App\\Notifications\\'.basename($file, '.php'))
        ->reject(fn (string $class) => is_subclass_of($class, ShownInNotificationCenter::class) || in_array($class, NotificationCenter::PENDING_WORDING, true))
        ->values()->all();

    expect($missing)->toBe([]);
    // And nothing is both worded and pending.
    expect(collect(NotificationCenter::PENDING_WORDING)->filter(fn (string $class) => is_subclass_of($class, ShownInNotificationCenter::class))->all())->toBe([]);
});

test('the admin-only lists are exactly the classes marked as sent only to administrators', function () {
    $marked = fn (string $interface) => collect(glob(app_path('Notifications/*.php')))
        ->map(fn (string $file) => 'App\\Notifications\\'.basename($file, '.php'))
        ->filter(fn (string $class) => is_subclass_of($class, $interface))
        ->sort()->values()->all();

    expect(collect(NotificationCenter::ADMIN_ONLY)->sort()->values()->all())->toBe($marked(SentOnlyToAdministrators::class))
        ->and(collect(NotificationCenter::SUPER_ADMIN_ONLY)->sort()->values()->all())->toBe($marked(SentOnlyToSuperAdministrators::class));
});

// Each worded class, fed its own toArray() (so a renamed key can't silently lose a name, date or link),
// with ids that would show if any leaked into the text.
dataset('ncent worded notifications', function () {
    $ward = fn () => tap(new User, fn (User $user) => $user->forceFill(['id' => 4242, 'name' => 'Kofi Mensah']));
    $therapy = fn () => tap(new Therapy, fn (Therapy $therapy) => $therapy->forceFill(['id' => 4243, 'name' => 'Talks']));
    $group = fn () => tap(new GroupTherapy, fn (GroupTherapy $group) => $group->forceFill(['id' => 4244, 'name' => 'Circle']));
    $suspension = fn (array $extra = []) => tap(new Suspension, fn (Suspension $suspension) => $suspension->forceFill(['id' => 4245, 'user_id' => 4242, 'scope' => 'account', 'ends_at' => now()->addDays(3), ...$extra]));
    $request = fn () => tap(new Request, fn (Request $request) => $request->forceFill(['id' => 4246]));
    $exception = fn () => tap(new SafeguardingException, fn (SafeguardingException $exception) => $exception->forceFill(['id' => 4247, 'ends_at' => now()->addDays(3)]));
    $counsellor = fn () => tap(new Counsellor, fn (Counsellor $counsellor) => $counsellor->forceFill(['id' => 4248]));

    return [
        'client paused' => [fn () => new ClientAccessPausedNotification($therapy(), 'Ama', $ward()), fn () => route('therapies.get', ['therapyId' => 4243]), 'Ama'],
        'member paused' => [fn () => new ClientAccessPausedNotification($group(), 'Ama', $ward()), fn () => route('group.therapies.get', ['groupTherapyId' => 4244]), 'participation'],
        'client resumed' => [fn () => new ClientAccessResumedNotification($therapy(), 'Ama', $ward(), now()->addDays(3)), fn () => route('therapies.get', ['therapyId' => 4243]), 'Ama'],
        'counsellor unavailable' => [fn () => new CounsellorUnavailableNotification('Dr Osei', now()->addDays(3), 2, true), null, 'Dr Osei'],
        'verification ending' => [fn () => new CounsellorVerificationEndingNotification(now()->addDays(3), 3), fn () => route('counsellor.dashboard'), 'ends soon'],
        'verification ending (admin)' => [fn () => new CounsellorVerificationEndingAdminNotification($counsellor(), now()->addDays(3), 3), fn () => route('administrator.counsellor_verification', ['view' => 'renewals']), 'ends soon'],
        'verification ended' => [fn () => new CounsellorVerificationEndedNotification(now()->subDay()), fn () => route('counsellor.dashboard'), 'ended'],
        'verification revoked' => [fn () => new CounsellorVerificationRevokedNotification('Licence lapsed'), fn () => route('counsellor.dashboard'), 'Licence lapsed'],
        'ward left group' => [fn () => new GroupTherapyMemberLeftGuardianNotification($group()), null, 'left'],
        'member left' => [fn () => new GroupTherapyMemberLeftNotification($group(), 'Ama', $ward()), fn () => route('group.therapies.get', ['groupTherapyId' => 4244]), 'Ama'],
        'access paused' => [fn () => new GuardianAccessPausedNotification, null, 'paused'],
        'access resumed' => [fn () => new GuardianAccessResumedNotification(now()->addDays(3)), null, 'continue'],
        'guardian safety notice' => [fn () => new GuardianSafetyNoticeNotification($ward()), null, 'check in'],
        'safety notice' => [fn () => new SafetyNoticeNotification, null, 'TalkTherapy'],
        'help waiting' => [fn () => new HelpRequestWaitingNotification(4249, 5, true), fn () => route('administrator.help_requests'), 'Still waiting'],
        'help invite' => [fn () => new HelpTherapyInviteNotification(4249, 'Dr Osei', true), fn () => route('help.chat', ['helpRequest' => 4249]), 'Dr Osei'],
        'flag raised' => [fn () => new SafetyFlagRaisedNotification(4250, 'pub-id', 'urgent'), fn () => route('administrator.safety_flags'), 'urgent'],
        'flag re-alert' => [fn () => new SafetyFlagReAlertNotification(4250, 'pub-id', 'urgent', 2), fn () => route('administrator.safety_flags'), 'Still waiting'],
        'flag withdrawn' => [fn () => new SafetyFlagWithdrawnNotification('pub-id', 'urgent', SafetyFlagWithdrawnNotification::ALERTED), fn () => route('administrator.safety_flags'), 'withdrawn'],
        'exception requested' => [fn () => new SafeguardingExceptionRequestedNotification($request()), fn () => route('administrator.safeguarding_exceptions'), 'requested'],
        'exception ending' => [fn () => new SafeguardingExceptionEndingSoonNotification($exception()), fn () => route('administrator.safeguarding_exceptions'), 'ends soon'],
        'exception approved' => [fn () => new SafeguardingExceptionRequestDecidedNotification($request(), now()->addDays(30)), null, 'approved'],
        'exception closed' => [fn () => new SafeguardingExceptionRequestDecidedNotification($request(), null, true), null, 'closed'],
        'exception not approved' => [fn () => new SafeguardingExceptionRequestDecidedNotification($request(), null), null, 'not approved'],
        'appeal submitted' => [fn () => new SuspensionAppealSubmittedNotification($suspension()), fn () => route('administrator.suspensions'), 'appealed'],
        'appeal lifted' => [fn () => new SuspensionAppealDecidedNotification($suspension(['appeal_decision' => 'lifted'])), null, 'accepted'],
        'appeal upheld' => [fn () => new SuspensionAppealDecidedNotification($suspension(['appeal_decision' => 'upheld'])), fn () => route('suspended.show'), 'not accepted'],
        'suspended' => [fn () => new SuspensionAppliedNotification($suspension()), fn () => route('suspended.show'), 'suspended'],
        'suspension ended' => [fn () => new SuspensionEndedNotification($suspension(), SuspensionEndedNotification::LIFTED), null, 'ended'],
        'ward suspended' => [fn () => new WardSuspendedNotification($suspension()), null, 'suspended'],
    ];
});

test('each worded notification reads its own saved data', function (Closure $make, ?Closure $url, string $mentions) {
    $notification = $make();
    $entry = $notification::present($notification->toArray(new User));
    $text = $entry->title.' '.$entry->message;

    expect($entry->title)->not->toBe('')->not->toBe('You have an update')
        ->and($text)->toContain($mentions)->not->toMatch('/42\d\d/')->not->toContain('pub-id')
        ->and($entry->url)->toBe($url ? $url() : null);
})->with('ncent worded notifications');

test('the list shows one fixed shape -- never the saved data, ids, a ward reference or the class name', function () {
    $viewer = User::factory()->adult()->create();
    $ward = User::factory()->adult()->create();
    ncentInsert($viewer, ClientAccessPausedNotification::class, ['type' => 'guardian_access.client_paused', 'therapyId' => 987654, 'clientName' => 'Ama', WardRef::KEY => WardRef::of($ward)]);

    $response = $this->actingAs($viewer)->getJson(route('notifications.index'))->assertOk();
    $item = $response->json('notifications.0');

    expect(array_keys($item))->toBe(['id', 'title', 'message', 'url', 'createdAt', 'read', 'audience', 'safetyNotice'])
        ->and($item['title'])->toBe("Ama's therapy is paused")
        ->and($item['url'])->toBe(route('therapies.get', ['therapyId' => 987654]))
        ->and($item['audience'])->toBe('user')
        ->and($item['read'])->toBeFalse();
    $body = $response->getContent();
    expect($body)->not->toContain(WardRef::of($ward))->not->toContain('wardRef')->not->toContain('Notification')->not->toContain('"data"');
});

test('an id-only class shows generically, and broken saved data never breaks the list', function () {
    $viewer = User::factory()->adult()->create();
    ncentInsert($viewer, TherapyCreatedNotification::class, ['therapy_id' => 1]);
    ncentInsert($viewer, ClientAccessPausedNotification::class, ['clientName' => ['not' => 'a string']]);

    $items = $this->actingAs($viewer)->getJson(route('notifications.index'))->assertOk()->json('notifications');

    expect(collect($items)->pluck('title')->sort()->values()->all())->toBe(["A client's therapy is paused", 'You have an update']);
});

test('one list holds the person\'s own and their counsellor profile\'s notifications, labelled, and nobody else\'s', function () {
    $counsellor = Counsellor::factory()->for(User::factory()->adult())->create();
    $viewer = $counsellor->user;
    ncentInsert($viewer, GuardianAccessPausedNotification::class, ['type' => 'guardian_access.paused']);
    ncentInsert($counsellor, CounsellorVerificationEndingNotification::class, ['type' => 'counsellor_verification.ending', 'expiresAt' => now()->addDays(5)->toJSON(), 'daysLeft' => 5]);
    $someoneElse = User::factory()->adult()->create();
    $theirs = ncentInsert($someoneElse, GuardianAccessPausedNotification::class);

    $items = collect($this->actingAs($viewer)->getJson(route('notifications.index'))->assertOk()->json('notifications'));

    expect($items->pluck('audience')->sort()->values()->all())->toBe(['counsellor', 'user'])
        ->and($items->pluck('id'))->not->toContain($theirs);
    $this->actingAs($viewer)->getJson(route('notifications.count'))->assertOk()->assertJson(['unread' => 2]);

    // Their counsellor profile's can be.
    $profiles = DB::table('notifications')->where('notifiable_type', Counsellor::class)->value('id');
    $this->actingAs($viewer)->postJson(route('notifications.read', $profiles))->assertOk();
    expect(DB::table('notifications')->where('id', $profiles)->value('read_at'))->not->toBeNull();

    // Someone else's notification can't be marked: the same 404 as one that doesn't exist.
    $this->actingAs($viewer)->postJson(route('notifications.read', $theirs))->assertNotFound();
    expect(DB::table('notifications')->where('id', $theirs)->value('read_at'))->toBeNull();
    $this->actingAs($viewer)->postJson(route('notifications.read', (string) Str::uuid()))->assertNotFound();
    $this->actingAs($viewer)->postJson('/notifications/not-a-uuid/read')->assertNotFound();
});

test('marking one or all read never marks a safety notice, which only its own dialog dismisses', function () {
    config(['safety.enabled' => true]);
    $viewer = User::factory()->adult()->create();
    $one = ncentInsert($viewer, GuardianAccessPausedNotification::class);
    $two = ncentInsert($viewer, GuardianAccessPausedNotification::class);
    $notice = ncentInsert($viewer, SafetyNoticeNotification::class, ['type' => 'safety.notice']);
    $guardianNotice = ncentInsert($viewer, GuardianSafetyNoticeNotification::class, ['type' => 'safety.guardian_notice', 'wardName' => 'Kofi']);

    $this->actingAs($viewer)->postJson(route('notifications.read', $one))->assertOk();
    expect(DB::table('notifications')->where('id', $one)->value('read_at'))->not->toBeNull();
    // Marking an already-read one is fine (opening it twice).
    $this->actingAs($viewer)->postJson(route('notifications.read', $one))->assertOk();
    $this->actingAs($viewer)->postJson(route('notifications.read', $notice))->assertNotFound();

    $this->actingAs($viewer)->postJson(route('notifications.read_all'))->assertOk()->assertJson(['marked' => 1]);

    expect(DB::table('notifications')->where('id', $two)->value('read_at'))->not->toBeNull()
        ->and(DB::table('notifications')->whereIn('id', [$notice, $guardianNotice])->whereNull('read_at')->count())->toBe(2);
    $this->actingAs($viewer)->getJson(route('notifications.count'))->assertJson(['unread' => 2]);

    $items = collect($this->actingAs($viewer)->getJson(route('notifications.index'))->json('notifications'))->keyBy('id');
    expect($items[$notice]['safetyNotice'])->toBeTrue()->and($items[$notice]['url'])->toBeNull()
        ->and($items[$notice]['message'])->toBe(SafetyNoticeNotification::message())
        ->and($items[$one]['safetyNotice'])->toBeFalse();
});

test('what only administrators are sent is hidden from anyone who isn\'t one now, and safety flags while off', function () {
    $wasAdmin = User::factory()->adult()->create();
    ncentInsert($wasAdmin, HelpRequestWaitingNotification::class, ['type' => 'help_request.waiting', 'helpRequestId' => 1, 'again' => false]);
    ncentInsert($wasAdmin, GuardianAccessPausedNotification::class);

    $hidden = ncentInsert($wasAdmin, SafetyFlagRaisedNotification::class, ['type' => 'safety_flag.raised']);
    config(['safety.enabled' => true]);

    $this->actingAs($wasAdmin)->getJson(route('notifications.count'))->assertJson(['unread' => 1]);
    expect($this->actingAs($wasAdmin)->getJson(route('notifications.index'))->json('notifications'))->toHaveCount(1);
    // Nor can they mark it.
    $this->actingAs($wasAdmin)->postJson(route('notifications.read', $hidden))->assertNotFound();
    expect(DB::table('notifications')->where('id', $hidden)->value('read_at'))->toBeNull();

    // What only super administrators are sent is hidden from an administrator who isn't one.
    $plainAdmin = ncentAdmin(AdministratorTypeEnum::normal);
    ncentInsert($plainAdmin, SafetyFlagReAlertNotification::class, ['type' => 'safety_flag.realert']);
    ncentInsert($plainAdmin, SafetyFlagRaisedNotification::class, ['type' => 'safety_flag.raised']);
    expect(collect($this->actingAs($plainAdmin)->getJson(route('notifications.index'))->json('notifications'))->pluck('title')->all())->toBe(['Safety alert']);

    $admin = ncentAdmin();
    ncentInsert($admin, HelpRequestWaitingNotification::class, ['type' => 'help_request.waiting', 'helpRequestId' => 1, 'again' => true]);
    ncentInsert($admin, SafetyFlagRaisedNotification::class, ['type' => 'safety_flag.raised', 'safetyFlag' => 'abc', 'urgency' => 'urgent']);
    ncentInsert($admin, SafetyNoticeNotification::class, ['type' => 'safety.notice']);

    config(['safety.enabled' => false]);
    expect(collect($this->actingAs($admin)->getJson(route('notifications.index'))->json('notifications'))->pluck('title')->all())
        ->toBe(['Still waiting: someone needs urgent help']);
    $this->actingAs($admin)->getJson(route('notifications.count'))->assertJson(['unread' => 1]);

    config(['safety.enabled' => true]);
    $titles = collect($this->actingAs($admin)->getJson(route('notifications.index'))->json('notifications'))->pluck('title');
    expect($titles)->toContain('Safety alert (urgent)')->toContain('A message from TalkTherapy')->toHaveCount(3);
});

test('pages come newest first by cursor, with no repeats or gaps, in a fixed number of queries', function () {
    $viewer = User::factory()->adult()->create();
    foreach (range(1, 20) as $i) {
        ncentInsert($viewer, GuardianAccessPausedNotification::class, [], ['created_at' => now()->subMinutes($i)]);
    }

    DB::enableQueryLog();
    $first = $this->actingAs($viewer)->getJson(route('notifications.index'))->assertOk();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($first->json('notifications'))->toHaveCount(15)->and($first->json('nextCursor'))->toBeString();
    $second = $this->actingAs($viewer)->getJson(route('notifications.index', ['cursor' => $first->json('nextCursor')]))->assertOk();
    expect($second->json('notifications'))->toHaveCount(5)->and($second->json('nextCursor'))->toBeNull();

    $all = collect([...$first->json('notifications'), ...$second->json('notifications')]);
    expect($all->pluck('id')->unique())->toHaveCount(20)
        ->and($all->pluck('createdAt')->all())->toBe($all->pluck('createdAt')->sortDesc()->values()->all());
    // The session/user lookups plus one page query, however many rows: never one per notification.
    expect($queries)->toBeLessThan(10);

    // A cursor that isn't one of ours -- garbage, or tampered with -- is just the first page, never an error.
    foreach (['garbage', 'MQ', 'eyJ9', base64_encode('{"_pointsToNextItems":true}'), base64_encode('{"created_at":["x"],"id":"1","_pointsToNextItems":true}')] as $cursor) {
        expect($this->actingAs($viewer)->getJson(route('notifications.index', ['cursor' => $cursor]))->assertOk()->json('notifications.0.id'))
            ->toBe($first->json('notifications.0.id'));
    }
});

test('a stored notification and marking read tell the person\'s own channel, with nothing in it', function () {
    Event::fake([NotificationsChangedEvent::class]);
    $counsellor = Counsellor::factory()->for(User::factory()->adult())->create();

    $counsellor->notify(new CounsellorVerificationEndingNotification(now()->addDays(3), 3));
    // Once (registered once; the mail channel doesn't ping), on the owner's own channel, empty.
    Event::assertDispatchedTimes(NotificationsChangedEvent::class, 1);
    Event::assertDispatched(NotificationsChangedEvent::class, fn (NotificationsChangedEvent $event) => $event->broadcastOn()[0]->name === "private-users.{$counsellor->user_id}"
        && $event->broadcastWith() === [] && $event->broadcastAs() === 'notifications.changed');

    // Marking one read tells them; marking an already-read one doesn't.
    $id = $counsellor->notifications()->value('id');
    Event::fake([NotificationsChangedEvent::class]);
    $this->actingAs($counsellor->user)->postJson(route('notifications.read', $id))->assertOk();
    $this->actingAs($counsellor->user)->postJson(route('notifications.read', $id))->assertOk();
    Event::assertDispatchedTimes(NotificationsChangedEvent::class, 1);
    $counsellor->notifications()->update(['read_at' => null]);

    Event::fake([NotificationsChangedEvent::class]);
    $this->actingAs($counsellor->user)->postJson(route('notifications.read_all'))->assertOk();
    Event::assertDispatchedTimes(NotificationsChangedEvent::class, 1);

    // Nothing to mark: nothing to tell.
    Event::fake([NotificationsChangedEvent::class]);
    $this->actingAs($counsellor->user)->postJson(route('notifications.read_all'))->assertOk()->assertJson(['marked' => 0]);
    Event::assertNotDispatched(NotificationsChangedEvent::class);
});

test('a guest and a suspended account can\'t use the center', function () {
    $this->getJson(route('notifications.index'))->assertUnauthorized();

    $suspended = User::factory()->adult()->create();
    $report = new Report(['description' => 'Something happened']);
    $report->addedby()->associate(User::factory()->adult()->create());
    $report->reportable()->associate(GroupTherapy::factory()->create());
    $report->save();
    Suspension::query()->forceCreate(['user_id' => $suspended->id, 'report_id' => $report->id, 'scope' => 'account', 'public_reason' => 'Conduct', 'starts_at' => now()->subMinute()]);

    $this->actingAs($suspended)->getJson(route('notifications.index'))->assertForbidden();
    $this->actingAs($suspended)->getJson(route('notifications.count'))->assertForbidden();
});
