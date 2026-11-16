<?php

use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Enums\SessionStatusEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\SessionDeletedNotification;
use App\Notifications\SessionStatusChangedNotification;
use Illuminate\Support\Facades\Notification;

// SCRUM-457: a group session's status notices (and its other session notices) go to the people the scheduler's own
// notices reach (GroupTherapy::sessionParticipants()) -- never an inactive counsellor, a former member, a closed
// account or a minor paused for a missing guardian -- plus a minor creator's guardians, as for one-to-one. The actor
// is never told about their own change. One-to-one recipients are unchanged.

beforeEach(fn () => Notification::fake());

function gnrCounsellor(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
}

function gnrSession(Therapy|GroupTherapy $for, array $attributes = []): Session
{
    return Session::factory()->create([
        'for_type' => $for::class, 'for_id' => $for->id, 'addedby_type' => Counsellor::class,
        'addedby_id' => $for instanceof Therapy ? $for->counsellor_id : $for->addedby_id,
        'status' => SessionStatusEnum::pending->value, 'payment_type' => 'FREE', 'type' => 'ONLINE',
        'start_time' => now()->subHours(2), 'end_time' => now()->subHour(), ...$attributes,
    ]);
}

// A counsellor-created group: its creator, a second active counsellor, an inactive one, an adult member, a former
// member, a minor with a guardian, a minor without one and a member whose account was closed.
function gnrGroup(): array
{
    $creator = gnrCounsellor();
    $active = gnrCounsellor();
    $inactive = gnrCounsellor();
    $group = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $creator->id, 'payment_type' => 'FREE', 'anonymous' => false, 'public' => false]);
    $group->counsellors()->attach($creator->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);
    $group->counsellors()->attach($active->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);
    $group->counsellors()->attach($inactive->id, ['state' => CounsellorGroupTherapyStateEnum::inactive->value, 'role' => 'NORMAL']);

    $member = User::factory()->adult()->create();
    $former = User::factory()->adult()->create();
    $coveredMinor = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);
    Guardianship::create(['guardian_id' => User::factory()->adult()->create()->id, 'ward_id' => $coveredMinor->id]);
    $pausedMinor = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);
    $closed = User::factory()->adult()->create();

    foreach ([$member, $former, $coveredMinor, $pausedMinor, $closed] as $user) {
        $group->users()->attach($user->id, ['anonymous' => false, 'background_story' => 'x']);
    }
    $group->allMemberships()->updateExistingPivot($former->id, ['left_at' => now()]);
    $closed->delete();

    return compact('creator', 'active', 'inactive', 'group', 'member', 'former', 'coveredMinor', 'pausedMinor', 'closed');
}

function gnrExcluded(array $g): array
{
    return [$g['inactive']->user, $g['former'], $g['pausedMinor'], $g['closed']];
}

test('a group session\'s status notice skips inactive counsellors, former members, paused minors and closed accounts', function (string $route, array $attributes) {
    $g = gnrGroup();
    $session = gnrSession($g['group'], $attributes);

    $this->actingAs($g['creator']->user)->postJson(route($route, $session->id))->assertSuccessful();

    Notification::assertSentTo([$g['active']->user, $g['member'], $g['coveredMinor']], SessionStatusChangedNotification::class);
    Notification::assertNotSentTo([...gnrExcluded($g), $g['creator']->user], SessionStatusChangedNotification::class);
})->with([
    'fail' => ['api.sessions.fail', []],
    'end' => ['api.sessions.end', []],
    'start' => ['api.sessions.in_session', ['start_time' => now()->subMinutes(5), 'end_time' => now()->addMinutes(55)]],
]);

test('a member who starts a group session isn\'t told about it, and its counsellors are', function () {
    $g = gnrGroup();
    $session = gnrSession($g['group'], ['start_time' => now()->subMinutes(5), 'end_time' => now()->addMinutes(55)]);

    $this->actingAs($g['member'])->postJson(route('api.sessions.in_session', $session->id))->assertSuccessful();

    Notification::assertSentTo([$g['creator']->user, $g['active']->user, $g['coveredMinor']], SessionStatusChangedNotification::class);
    Notification::assertNotSentTo([...gnrExcluded($g), $g['member']], SessionStatusChangedNotification::class);
});

test('a group session\'s notice recipients are its session participants other than the actor', function () {
    $g = gnrGroup();
    $session = gnrSession($g['group']);

    expect($session->otherParticipants($g['creator']->user)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$g['active']->user_id, $g['member']->id, $g['coveredMinor']->id])->sort()->values()->all());
});

test('a group session\'s deleted notice skips inactive counsellors, former members and paused minors', function () {
    $g = gnrGroup();
    $session = gnrSession($g['group'], ['start_time' => now()->addDay(), 'end_time' => now()->addDay()->addHour()]);

    $this->actingAs($g['creator']->user)->deleteJson(route('api.sessions.delete', $session->id))->assertSuccessful();

    Notification::assertSentTo([$g['active']->user, $g['member'], $g['coveredMinor']], SessionDeletedNotification::class);
    Notification::assertNotSentTo([...gnrExcluded($g), $g['creator']->user], SessionDeletedNotification::class);
});

test('closing an in-person group session as a no-show skips inactive counsellors, former members and paused minors', function () {
    $g = gnrGroup();
    $g['group']->update(['allow_in_person' => true]);
    $session = gnrSession($g['group'], [
        'type' => 'IN_PERSON', 'latitude' => 5.6, 'longitude' => -0.18, 'landmark' => 'gnr Room 1',
        'start_time' => now()->subMinutes(10), 'end_time' => now()->addMinutes(50),
    ]);
    $actor = $g['creator']->user;
    $this->travel(2)->hours();

    $register = $this->actingAs($actor)->getJson(route('api.session.attendance.index', ['sessionId' => $session->id]))->assertOk()->json('register');
    expect($register)->not->toBeEmpty();
    $absent = collect($register)->map(fn (array $row) => ['key' => $row['key'], 'status' => 'ABSENT'])->all();
    $this->actingAs($actor)->postJson(route('api.session.attendance.store', ['sessionId' => $session->id]), ['attendance' => $absent])->assertSuccessful();
    $this->actingAs($actor)->postJson(route('api.session.attendance.close_no_show', ['sessionId' => $session->id]))
        ->assertSuccessful()->assertJsonPath('session.status', SessionStatusEnum::failed->value);

    Notification::assertSentTo([$g['active']->user, $g['member'], $g['coveredMinor']], SessionStatusChangedNotification::class);
    Notification::assertNotSentTo([...gnrExcluded($g), $actor], SessionStatusChangedNotification::class);
});

test('a minor-created group\'s session notices also reach the creator\'s guardian, as for one-to-one', function () {
    $minor = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);
    $guardian = User::factory()->adult()->create();
    Guardianship::create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id]);
    $counsellor = gnrCounsellor();
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $minor->id, 'payment_type' => 'FREE', 'anonymous' => false, 'public' => false, 'client_was_minor_at_creation' => true]);
    $group->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);
    $session = gnrSession($group, ['addedby_id' => $counsellor->id]);

    $this->actingAs($counsellor->user)->postJson(route('api.sessions.fail', $session->id))->assertSuccessful();

    Notification::assertSentTo([$minor, $guardian], SessionStatusChangedNotification::class);
    Notification::assertNotSentTo($counsellor->user, SessionStatusChangedNotification::class);
});

test('one-to-one session notices still go to the other side and a minor client\'s guardian', function () {
    $minor = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);
    $guardian = User::factory()->adult()->create();
    Guardianship::create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id]);
    $counsellor = gnrCounsellor();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $minor->id, 'counsellor_id' => $counsellor->id, 'payment_type' => 'FREE', 'public' => false, 'client_was_minor_at_creation' => true]);
    $session = gnrSession($therapy);

    expect($session->otherParticipants($counsellor->user)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$minor->id, $guardian->id])->sort()->values()->all());

    $this->actingAs($counsellor->user)->postJson(route('api.sessions.fail', $session->id))->assertSuccessful();

    Notification::assertSentTo([$minor, $guardian], SessionStatusChangedNotification::class);
    Notification::assertNotSentTo($counsellor->user, SessionStatusChangedNotification::class);
});

test('a one-to-one client who ends a session tells only the counsellor', function () {
    $client = User::factory()->adult()->create();
    $counsellor = gnrCounsellor();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id, 'payment_type' => 'FREE', 'public' => false]);
    $session = gnrSession($therapy);

    $this->actingAs($client)->postJson(route('api.sessions.end', $session->id))->assertSuccessful();

    Notification::assertSentTo($counsellor->user, SessionStatusChangedNotification::class);
    Notification::assertNotSentTo($client, SessionStatusChangedNotification::class);
});
