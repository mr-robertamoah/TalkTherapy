<?php

use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Enums\NotificationCategoryEnum;
use App\Enums\SessionStatusEnum;
use App\Enums\SuspensionScopeEnum;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Report;
use App\Models\Session;
use App\Models\Suspension;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\SessionMarkedAbsentNotification;
use App\Support\GroupMemberKeys;
use App\Support\NotificationCategories;
use Illuminate\Support\Facades\Notification;

// SCRUM-446 (SCRUM-115): a client or member marked ABSENT from an in-person session is told -- themselves only, never
// on PRESENT or an unchanged mark, and never with the place or anyone else's name. And the register tells the page
// whether the viewer may save it.

beforeEach(fn () => Notification::fake());

function smanCounsellor(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
}

function smanTherapy(): array
{
    $client = User::factory()->adult()->create();
    $counsellor = smanCounsellor();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id, 'allow_in_person' => true, 'payment_type' => 'FREE', 'public' => false]);

    return [$client, $counsellor, $therapy];
}

function smanSession(Therapy|GroupTherapy $for, array $attributes = []): Session
{
    return Session::factory()->create([
        'for_type' => $for::class, 'for_id' => $for->id, 'addedby_type' => Counsellor::class,
        'addedby_id' => $for instanceof Therapy ? $for->counsellor_id : $for->addedby_id,
        'name' => 'sman Tuesday Circle',
        'status' => SessionStatusEnum::pending->value, 'payment_type' => 'FREE', 'type' => 'IN_PERSON',
        'latitude' => 5.6123, 'longitude' => -0.1876, 'landmark' => 'sman Secret Room 9',
        'start_time' => now()->subMinutes(70), 'end_time' => now()->subMinutes(10), ...$attributes,
    ]);
}

function smanRecord($test, User $as, Session $session, array $entries)
{
    return $test->actingAs($as)->postJson(route('api.session.attendance.store', ['sessionId' => $session->id]), ['attendance' => $entries]);
}

function smanRead($test, User $as, Session $session)
{
    return $test->actingAs($as)->getJson(route('api.session.attendance.index', ['sessionId' => $session->id]));
}

test('a client marked absent is told; marked present they are not', function () {
    [$client, $counsellor, $therapy] = smanTherapy();
    $session = smanSession($therapy);

    smanRecord($this, $counsellor->user, $session, [['key' => 'client', 'status' => 'PRESENT']])->assertSuccessful();
    Notification::assertNothingSent();

    smanRecord($this, $counsellor->user, $session, [['key' => 'client', 'status' => 'ABSENT']])->assertSuccessful();
    Notification::assertSentToTimes($client, SessionMarkedAbsentNotification::class, 1);
    Notification::assertNotSentTo($counsellor->user, SessionMarkedAbsentNotification::class);
});

test('saving an unchanged ABSENT again doesn\'t tell them twice', function () {
    [$client, $counsellor, $therapy] = smanTherapy();
    $session = smanSession($therapy);

    smanRecord($this, $counsellor->user, $session, [['key' => 'client', 'status' => 'ABSENT']])->assertSuccessful();
    smanRecord($this, $counsellor->user, $session, [['key' => 'client', 'status' => 'ABSENT']])->assertSuccessful();

    Notification::assertSentToTimes($client, SessionMarkedAbsentNotification::class, 1);
});

test('a refused save tells nobody', function () {
    [$client, $counsellor, $therapy] = smanTherapy();
    $session = smanSession($therapy, ['start_time' => now()->subDays(4), 'end_time' => now()->subDays(3)]);

    smanRecord($this, $counsellor->user, $session, [['key' => 'client', 'status' => 'ABSENT']])->assertStatus(422);

    Notification::assertNothingSent();
});

test('the notice names the session and its date -- never the place or the counsellor', function () {
    [$client, $counsellor, $therapy] = smanTherapy();
    $session = smanSession($therapy);

    smanRecord($this, $counsellor->user, $session, [['key' => 'client', 'status' => 'ABSENT']])->assertSuccessful();

    Notification::assertSentTo($client, SessionMarkedAbsentNotification::class, function (SessionMarkedAbsentNotification $notification, array $channels) use ($client, $session, $therapy, $counsellor) {
        $data = $notification->toArray($client);
        $entry = SessionMarkedAbsentNotification::present($data);
        $everything = json_encode($data).' '.$entry->title.' '.$entry->message.' '.implode(' ', $notification->toMail($client)->introLines);

        expect($data)->toBe([
            'session_id' => $session->id,
            'sessionName' => 'sman Tuesday Circle',
            'sessionDate' => $session->start_time->toIso8601String(),
            'paid' => false,
            'therapyId' => $therapy->id,
        ])
            ->and($entry->message)->toBe('You were marked absent from sman Tuesday Circle on '.$session->start_time->toFormattedDateString().'. If that\'s wrong, talk to your counsellor.')
            ->and($entry->url)->toBe(route('therapies.get', ['therapyId' => $therapy->id]))
            ->and($everything)->not->toContain('Secret Room')->not->toContain('5.61')->not->toContain('-0.18')
            ->not->toContain($counsellor->user->name);

        return true;
    });
});

test('a refund is mentioned only for a paid session, in the bell and the email alike', function () {
    $client = User::factory()->adult()->create(['email_verified_at' => now()]);
    $therapy = smanTherapy()[2];

    foreach (['FREE' => false, 'PAID' => true] as $paymentType => $mentionsRefund) {
        $notification = new SessionMarkedAbsentNotification(smanSession($therapy, ['payment_type' => $paymentType]));
        $data = $notification->toArray($client);
        $bell = SessionMarkedAbsentNotification::present($data)->message;
        $email = implode(' ', $notification->toMail($client)->introLines);

        expect($data['paid'])->toBe($mentionsRefund)
            ->and(str_contains($bell, 'ask for a refund'))->toBe($mentionsRefund)
            ->and(str_contains($email, 'ask for a refund'))->toBe($mentionsRefund)
            ->and(str_ends_with($bell, $mentionsRefund ? 'talk to your counsellor or ask for a refund.' : 'talk to your counsellor.'))->toBeTrue();
    }

    // A notice saved before the flag existed doesn't promise a refund.
    expect(SessionMarkedAbsentNotification::present(['sessionName' => 'x'])->message)->not->toContain('refund');
});

test('it goes by email too unless the sessions email is switched off; in-app always', function () {
    $verified = User::factory()->adult()->create(['email_verified_at' => now()]);
    $unverified = User::factory()->adult()->create(['email_verified_at' => null]);
    $session = smanSession(smanTherapy()[2]);
    $notification = new SessionMarkedAbsentNotification($session);

    expect($notification->via($verified))->toBe(['database', 'mail'])
        ->and($notification->via($unverified))->toBe(['database'])
        ->and(NotificationCategories::MAP[SessionMarkedAbsentNotification::class])->toBe(NotificationCategoryEnum::sessions);
});

test('in a group, only the member marked absent is told -- an anonymous one too, and nobody about them', function () {
    $counsellor = smanCounsellor();
    $group = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id, 'allow_in_person' => true, 'payment_type' => 'FREE', 'anonymous' => false, 'public' => false]);
    $group->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);
    $named = User::factory()->adult()->create(['firstName' => 'sman Named', 'lastName' => 'Member']);
    $hidden = User::factory()->adult()->create(['firstName' => 'sman Hidden', 'lastName' => 'Member']);
    $group->users()->attach($named->id, ['anonymous' => false, 'background_story' => 'x', 'created_at' => now()->subDay()]);
    $group->users()->attach($hidden->id, ['anonymous' => true, 'background_story' => 'x', 'created_at' => now()->subDay()]);
    $session = smanSession($group);
    $keys = GroupMemberKeys::current($group->fresh());

    smanRecord($this, $counsellor->user, $session, [
        ['key' => $keys->firstWhere('user.id', $named->id)['key'], 'status' => 'PRESENT'],
        ['key' => $keys->firstWhere('user.id', $hidden->id)['key'], 'status' => 'ABSENT'],
    ])->assertSuccessful();

    Notification::assertSentToTimes($hidden, SessionMarkedAbsentNotification::class, 1);
    Notification::assertNotSentTo([$named, $counsellor->user], SessionMarkedAbsentNotification::class);
    Notification::assertSentTo($hidden, SessionMarkedAbsentNotification::class, function (SessionMarkedAbsentNotification $notification) use ($hidden, $group) {
        $data = $notification->toArray($hidden);

        expect(json_encode($data))->not->toContain('sman Named')->not->toContain('sman Hidden')
            ->and($data['groupTherapyId'])->toBe($group->id);

        return true;
    });
});

test('the register says whether the viewer may save it now', function () {
    [$client, $counsellor, $therapy] = smanTherapy();
    $open = smanSession($therapy);
    $closed = smanSession($therapy, ['start_time' => now()->subDays(4), 'end_time' => now()->subDays(3)]);
    $failed = smanSession($therapy, ['status' => SessionStatusEnum::failed->value]);
    $admin = User::factory()->adult()->has(Administrator::factory())->create();

    smanRead($this, $counsellor->user, $open)->assertOk()->assertJsonPath('canRecord', true);
    smanRead($this, $counsellor->user, $closed)->assertOk()->assertJsonPath('canRecord', false);
    smanRead($this, $counsellor->user, $failed)->assertOk()->assertJsonPath('canRecord', false);
    smanRead($this, $admin, $open)->assertOk()->assertJsonPath('canRecord', false)->assertJsonPath('register.0.key', 'client');
    // A client sees only their own mark -- no register, and nothing about saving it.
    smanRead($this, $client, $open)->assertOk()->assertJsonPath('register', null)->assertJsonMissingPath('canRecord');
});

test('a suspended counsellor may read the register but not save it', function () {
    [, $counsellor, $therapy] = smanTherapy();
    $session = smanSession($therapy);
    $report = new Report(['description' => 'Conduct']);
    $report->addedby()->associate(User::factory()->adult()->create());
    $report->reportable()->associate(GroupTherapy::factory()->create());
    $report->save();
    Suspension::query()->forceCreate(['user_id' => $counsellor->user_id, 'report_id' => $report->id, 'scope' => SuspensionScopeEnum::counsellor->value,
        'public_reason' => 'Conduct', 'starts_at' => now()->subMinute(), 'ends_at' => now()->addDay()]);

    smanRead($this, $counsellor->user, $session)->assertOk()->assertJsonPath('canRecord', false)->assertJsonPath('register.0.key', 'client');
});
