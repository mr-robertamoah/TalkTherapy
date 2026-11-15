<?php

use App\Actions\Session\FailUnheldSessionsAction;
use App\Actions\SessionAttendance\CloseSessionAsNoShowAction;
use App\Actions\SessionAttendance\EnsureCanRecordSessionAttendanceAction;
use App\Actions\SessionAttendance\RecordSessionAttendanceAction;
use App\Actions\Suspension\EnsureCanActAsCounsellorAction;
use App\Actions\User\ExportYourDataAction;
use App\Actions\User\GetYourDataAction;
use App\Enums\ConstantsEnum;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Enums\SessionStatusEnum;
use App\Enums\SuspensionScopeEnum;
use App\Events\SessionUpdatedEvent;
use App\Exceptions\GuardianRequiredException;
use App\Models\AdminContentAccess;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\OrganizationInvoiceLine;
use App\Models\Report;
use App\Models\Session;
use App\Models\SessionAttendance;
use App\Models\Suspension;
use App\Models\Therapy;
use App\Models\User;
use App\Support\GroupMemberKeys;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

// SCRUM-445 (SCRUM-115): attendance at in-person sessions -- a counsellor of the therapy or group marks each client or
// member present or absent, from the start until 48 hours after the end; a client or member sees only their own mark;
// counsellors and administrators (logged) see the register; when everyone was absent the session closes as FAILED.

beforeEach(fn () => Notification::fake());

function attCounsellor(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
}

function attTherapy(array $therapy = []): array
{
    $client = User::factory()->adult()->create();
    $counsellor = attCounsellor();
    $t = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id, 'allow_in_person' => true, 'payment_type' => 'FREE', 'public' => false, ...$therapy]);

    return [$client, $counsellor, $t];
}

function attSession(Therapy|GroupTherapy $for, array $attributes = []): Session
{
    return Session::factory()->create([
        'for_type' => $for::class, 'for_id' => $for->id, 'addedby_type' => Counsellor::class,
        'addedby_id' => $for instanceof Therapy ? $for->counsellor_id : $for->addedby_id,
        'status' => SessionStatusEnum::pending->value, 'payment_type' => 'FREE', 'type' => 'IN_PERSON',
        'latitude' => 5.6, 'longitude' => -0.18, 'landmark' => 'att Room 3',
        'start_time' => now()->subMinutes(10), 'end_time' => now()->addMinutes(50), ...$attributes,
    ]);
}

// A counsellor-created group with an active counsellor and two members (the second anonymous).
function attGroup(): array
{
    $counsellor = attCounsellor();
    $group = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id, 'allow_in_person' => true, 'payment_type' => 'FREE', 'anonymous' => false, 'public' => false]);
    $group->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);
    [$named, $anonymous] = [User::factory()->adult()->create(['firstName' => 'att Named', 'lastName' => 'Member']), User::factory()->adult()->create(['firstName' => 'att Hidden', 'lastName' => 'Member'])];
    $group->users()->attach($named->id, ['anonymous' => false, 'background_story' => 'x']);
    $group->users()->attach($anonymous->id, ['anonymous' => true, 'background_story' => 'x']);

    return [$counsellor, $group, $named, $anonymous];
}

function attKeyOf(GroupTherapy $group, User $member): string
{
    return GroupMemberKeys::current($group->fresh())->firstWhere('user.id', $member->id)['key'];
}

function attRecord($test, User $as, Session $session, array $entries)
{
    return $test->actingAs($as)->postJson(route('api.session.attendance.store', ['sessionId' => $session->id]), ['attendance' => $entries]);
}

function attRead($test, User $as, Session $session)
{
    return $test->actingAs($as)->getJson(route('api.session.attendance.index', ['sessionId' => $session->id]));
}

function attClose($test, User $as, Session $session)
{
    return $test->actingAs($as)->postJson(route('api.session.attendance.close_no_show', ['sessionId' => $session->id]));
}

// The session fetch (api.sessions.show), unwrapped whichever way JsonResource wrapping is set in this process.
function attShow($test, User $as, Session $session): array
{
    $json = $test->actingAs($as)->getJson(route('api.sessions.show', ['sessionId' => $session->id]))->assertOk()->json();
    $json = $json['data'] ?? $json;
    expect($json['id'])->toBe($session->id);

    return $json;
}

function attAdmin(): User
{
    return User::factory()->adult()->has(Administrator::factory())->create();
}

function attSuspendAsCounsellor(User $person): void
{
    $report = new Report(['description' => 'Conduct']);
    $report->addedby()->associate(User::factory()->adult()->create());
    $report->reportable()->associate(GroupTherapy::factory()->create());
    $report->save();

    Suspension::query()->forceCreate(['user_id' => $person->id, 'report_id' => $report->id, 'scope' => SuspensionScopeEnum::counsellor->value,
        'public_reason' => 'Conduct', 'starts_at' => now()->subMinute(), 'ends_at' => now()->addDay()]);
}

test('the counsellor marks the client and corrects it within the window; an unchanged mark keeps its time', function () {
    [$client, $counsellor, $therapy] = attTherapy();
    $session = attSession($therapy);

    attRecord($this, $counsellor->user, $session, [['key' => 'client', 'status' => 'PRESENT']])
        ->assertSuccessful()
        ->assertJsonPath('register.0.key', 'client')
        ->assertJsonPath('register.0.status', 'PRESENT');

    $row = SessionAttendance::query()->sole();
    expect($row->user_id)->toBe($client->id)
        ->and($row->marked_by_counsellor_id)->toBe($counsellor->id)
        ->and($row->status)->toBe('PRESENT');

    $this->travel(2)->hours();
    attRecord($this, $counsellor->user, $session, [['key' => 'client', 'status' => 'PRESENT']])->assertSuccessful();
    expect($row->fresh()->marked_at->equalTo($row->marked_at))->toBeTrue();

    $this->travel(40)->hours(); // still inside 48 hours after the end
    attRecord($this, $counsellor->user, $session, [['key' => 'client', 'status' => 'ABSENT']])->assertSuccessful();
    expect(SessionAttendance::query()->sole()->status)->toBe('ABSENT')
        ->and(SessionAttendance::query()->sole()->marked_at->greaterThan($row->marked_at))->toBeTrue()
        ->and($session->fresh()->status)->toBe(SessionStatusEnum::pending->value); // a mark never changes the session
});

test('marking is refused before the start and once the window has closed', function () {
    [, $counsellor, $therapy] = attTherapy();
    $later = attSession($therapy, ['start_time' => now()->addHour(), 'end_time' => now()->addHours(2)]);
    $past = attSession($therapy, ['start_time' => now()->subHours(51), 'end_time' => now()->subHours(50)]);

    attRecord($this, $counsellor->user, $later, [['key' => 'client', 'status' => 'PRESENT']])
        ->assertStatus(422)->assertJsonPath('message', EnsureCanRecordSessionAttendanceAction::NOT_STARTED);
    attRecord($this, $counsellor->user, $past, [['key' => 'client', 'status' => 'PRESENT']])
        ->assertStatus(422)->assertJsonPath('message', EnsureCanRecordSessionAttendanceAction::WINDOW_CLOSED);

    config(['limits.attendance_window_hours' => 72]);
    attRecord($this, $counsellor->user, $past, [['key' => 'client', 'status' => 'PRESENT']])->assertSuccessful();
    expect(SessionAttendance::query()->count())->toBe(1);
});

test('online sessions keep no attendance, and their resource carries none', function () {
    [$client, $counsellor, $therapy] = attTherapy();
    $online = attSession($therapy, ['type' => 'ONLINE', 'latitude' => null, 'longitude' => null, 'landmark' => null]);

    attRecord($this, $counsellor->user, $online, [['key' => 'client', 'status' => 'PRESENT']])
        ->assertStatus(422)->assertJsonPath('message', EnsureCanRecordSessionAttendanceAction::ONLY_IN_PERSON);
    attRead($this, $client, $online)->assertStatus(422);

    $shown = attShow($this, $counsellor->user, $online);
    expect($shown)->not->toHaveKey('attendance')->not->toHaveKey('viewerAttendance')
        ->and(SessionAttendance::query()->count())->toBe(0);
});

test('a client, another counsellor or an administrator can\'t mark attendance', function () {
    [$client, , $therapy] = attTherapy();
    $session = attSession($therapy);

    foreach ([$client, attCounsellor()->user, attAdmin()] as $who) {
        attRecord($this, $who, $session, [['key' => 'client', 'status' => 'ABSENT']])
            ->assertStatus(422)->assertJsonPath('message', EnsureCanRecordSessionAttendanceAction::NOT_ALLOWED);
        attClose($this, $who, $session)->assertStatus(422);
    }

    expect(SessionAttendance::query()->count())->toBe(0);
});

test('a suspended counsellor can\'t mark attendance or close the session, but can still read the register', function () {
    [, $counsellor, $therapy] = attTherapy();
    $session = attSession($therapy);
    attSuspendAsCounsellor($counsellor->user);

    attRecord($this, $counsellor->user, $session, [['key' => 'client', 'status' => 'ABSENT']])
        ->assertStatus(403)->assertJsonPath('message', EnsureCanActAsCounsellorAction::SUSPENDED);
    attClose($this, $counsellor->user, $session)->assertStatus(403);
    attRead($this, $counsellor->user, $session)->assertOk()->assertJsonPath('register.0.status', null);
    expect(SessionAttendance::query()->count())->toBe(0);
});

test('attendance isn\'t marked while the therapy can\'t proceed (paused for a minor\'s missing guardian)', function () {
    // A therapy switched to in-person before the adults-only rule, whose client is a minor with no guardian.
    [$client, $counsellor, $therapy] = attTherapy();
    $client->forceFill(['dob' => now()->subYears(15)->toDateString()])->save();
    $session = attSession($therapy);

    attRecord($this, $counsellor->user, $session, [['key' => 'client', 'status' => 'PRESENT']])
        ->assertStatus(403)->assertJsonPath('message', GuardianRequiredException::CLIENT_PAUSED);
    expect(SessionAttendance::query()->count())->toBe(0);
});

test('an administrator reads the register, and each read is logged', function () {
    [$client, $counsellor, $therapy] = attTherapy();
    $session = attSession($therapy);
    attRecord($this, $counsellor->user, $session, [['key' => 'client', 'status' => 'PRESENT']])->assertSuccessful();
    $admin = attAdmin();

    $logged = fn () => AdminContentAccess::query()->where('admin_user_id', $admin->id)->where('resource', AdminContentAccess::SESSION_ATTENDANCE)->count();

    // Once per ten minutes, as the session fetch logs its reads.
    attRead($this, $admin, $session)->assertOk()->assertJsonPath('register.0.status', 'PRESENT');
    attRead($this, $admin, $session)->assertOk();
    expect($logged())->toBe(1);
    $this->travel(11)->minutes();
    attRead($this, $admin, $session)->assertOk();
    expect($logged())->toBe(2);

    // The session fetch gives an administrator the register too, logged with it.
    $shown = attShow($this, $admin, $session);
    expect($shown['attendance'][0]['status'])->toBe('PRESENT')->and($logged())->toBe(3);

    // A counsellor's reads aren't logged.
    attRead($this, $counsellor->user, $session)->assertOk();
    expect(AdminContentAccess::query()->where('admin_user_id', $counsellor->user_id)->exists())->toBeFalse()
        ->and($client->exists)->toBeTrue();
});

test('an administrator involved in the therapy (a guardian of its client) sees only their own mark, unlogged', function () {
    [$client, $counsellor, $therapy] = attTherapy();
    $session = attSession($therapy);
    attRecord($this, $counsellor->user, $session, [['key' => 'client', 'status' => 'PRESENT']])->assertSuccessful();
    $admin = attAdmin();
    Guardianship::query()->create(['guardian_id' => $admin->id, 'ward_id' => $client->id]);

    attRead($this, $admin, $session)->assertOk()->assertJsonPath('register', null)->assertJsonPath('viewerAttendance', null);
    expect(attShow($this, $admin, $session))->not->toHaveKey('attendance')
        ->and(AdminContentAccess::query()->where('admin_user_id', $admin->id)->where('resource', AdminContentAccess::SESSION_ATTENDANCE)->exists())->toBeFalse();
});

test('a missing session gets the same refusal as one the caller can\'t use', function () {
    [, $counsellor, $therapy] = attTherapy();
    $session = attSession($therapy);
    $outsider = attCounsellor()->user;
    $missing = Session::factory()->make(['id' => 987654]);

    foreach ([$outsider, $counsellor->user] as $who) {
        $refused = $who->is($outsider) ? $session : $missing;
        attRecord($this, $who, $refused, [['key' => 'client', 'status' => 'PRESENT']])->assertStatus(422)->assertJsonPath('message', EnsureCanRecordSessionAttendanceAction::NOT_ALLOWED);
        attRead($this, $who, $refused)->assertStatus(422)->assertJsonPath('message', EnsureCanRecordSessionAttendanceAction::NOT_ALLOWED);
        attClose($this, $who, $refused)->assertStatus(422)->assertJsonPath('message', EnsureCanRecordSessionAttendanceAction::NOT_ALLOWED);
    }
});

test('a client sees only their own mark, never the register or who marked it', function () {
    [$client, $counsellor, $therapy] = attTherapy();
    $session = attSession($therapy);
    attRecord($this, $counsellor->user, $session, [['key' => 'client', 'status' => 'ABSENT']])->assertSuccessful();

    $own = attRead($this, $client, $session)->assertOk()->json();
    expect($own['register'])->toBeNull()
        ->and(array_keys($own['viewerAttendance']))->toBe(['status', 'markedAt'])
        ->and($own['viewerAttendance']['status'])->toBe('ABSENT');

    $shown = attShow($this, $client, $session);
    expect($shown)->not->toHaveKey('attendance')
        ->and($shown['viewerAttendance']['status'])->toBe('ABSENT');

    $counsellorView = attShow($this, $counsellor->user, $session);
    expect($counsellorView['attendance'][0]['status'])->toBe('ABSENT')
        ->and($counsellorView['viewerAttendance'])->toBeNull();

    attRead($this, User::factory()->adult()->create(), $session)->assertStatus(422);
});

test('a group\'s register goes by membership key: unknown keys and user ids are refused, members never see each other', function () {
    [$counsellor, $group, $named, $anonymous] = attGroup();
    $session = attSession($group);
    $namedKey = attKeyOf($group, $named);
    $anonymousKey = attKeyOf($group, $anonymous);

    // Refused as a whole: nothing is saved.
    attRecord($this, $counsellor->user, $session, [['key' => $namedKey, 'status' => 'PRESENT'], ['key' => '999999', 'status' => 'ABSENT']])
        ->assertStatus(422)->assertJsonPath('message', RecordSessionAttendanceAction::UNKNOWN_PERSON);
    // A member's user id is not their key (an id no membership row has, so it can't match one by chance).
    $byId = User::factory()->adult()->create(['id' => 900001]);
    $group->users()->attach($byId->id, ['anonymous' => false, 'background_story' => 'x']);
    attRecord($this, $counsellor->user, $session, [['key' => (string) $byId->id, 'status' => 'PRESENT']])->assertStatus(422);
    attRecord($this, $counsellor->user, $session, [['key' => 'client', 'status' => 'PRESENT']])->assertStatus(422);
    attRecord($this, $counsellor->user, $session, [['key' => GroupMemberKeys::CREATOR, 'status' => 'PRESENT']])->assertStatus(422);
    $group->allMemberships()->detach($byId->id);
    expect(SessionAttendance::query()->count())->toBe(0);

    $register = attRecord($this, $counsellor->user, $session, [['key' => $namedKey, 'status' => 'PRESENT'], ['key' => $anonymousKey, 'status' => 'ABSENT']])
        ->assertSuccessful()->json('register');

    expect(collect($register)->pluck('key')->all())->toBe([$namedKey, $anonymousKey])
        ->and(collect($register)->pluck('name')->all())->toBe(['att Named Member', ConstantsEnum::anonymousUserLabel->value])
        ->and(collect($register)->pluck('status')->all())->toBe(['PRESENT', 'ABSENT'])
        ->and(json_encode($register))->not->toContain('att Hidden Member')
        ->and(array_keys($register[0]))->toBe(['key', 'name', 'status', 'markedAt'])
        ->and(SessionAttendance::query()->where('user_id', $anonymous->id)->value('status'))->toBe('ABSENT');

    // Each member sees only their own row.
    expect(attRead($this, $named, $session)->assertOk()->json())->toMatchArray(['register' => null, 'viewerAttendance' => ['status' => 'PRESENT', 'markedAt' => SessionAttendance::query()->where('user_id', $named->id)->sole()->marked_at->toIso8601String()]]);
    $shown = attShow($this, $anonymous, $session);
    expect($shown)->not->toHaveKey('attendance')->and($shown['viewerAttendance']['status'])->toBe('ABSENT');
});

test('a member who joined after the session ended isn\'t on its register; one who left keeps their row', function () {
    [$counsellor, $group, $named, $anonymous] = attGroup();
    $session = attSession($group, ['start_time' => now()->subHours(3), 'end_time' => now()->subHours(2)]);
    foreach ([$named, $anonymous] as $member) {
        $group->users()->updateExistingPivot($member->id, ['created_at' => now()->subDay()]);
    }
    $namedKey = attKeyOf($group, $named);
    attRecord($this, $counsellor->user, $session, [['key' => $namedKey, 'status' => 'PRESENT']])->assertSuccessful();

    $late = User::factory()->adult()->create();
    $group->users()->attach($late->id, ['anonymous' => false, 'background_story' => 'x']);
    attRecord($this, $counsellor->user, $session, [['key' => attKeyOf($group, $late), 'status' => 'ABSENT']])
        ->assertStatus(422)->assertJsonPath('message', RecordSessionAttendanceAction::UNKNOWN_PERSON);

    $group->allMemberships()->updateExistingPivot($named->id, ['left_at' => now()]);
    $register = attRead($this, $counsellor->user, $session)->assertOk()->json('register');
    expect(collect($register)->firstWhere('key', $namedKey)['status'])->toBe('PRESENT')
        // Someone no longer a member is told apart by when they left, never by name (GroupMemberKeys::labels()).
        ->and(collect($register)->pluck('name')->all())->toBe(['Former member, left '.now()->format('M Y'), ConstantsEnum::anonymousUserLabel->value])
        ->and(collect($register)->pluck('key')->all())->toBe([$namedKey, attKeyOf($group, $anonymous)]);
});

test('a member who left after the session is still on its register, and blocks a no-show close until marked', function () {
    [$counsellor, $group, $named, $anonymous] = attGroup();
    $session = attSession($group, ['start_time' => now()->subHours(3), 'end_time' => now()->subHours(2)]);
    foreach ([$named, $anonymous] as $member) {
        $group->users()->updateExistingPivot($member->id, ['created_at' => now()->subDay()]);
    }
    $leaverKey = attKeyOf($group, $named);
    $group->allMemberships()->updateExistingPivot($named->id, ['left_at' => now()->subHour()]);

    attRecord($this, $counsellor->user, $session, [['key' => attKeyOf($group, $anonymous), 'status' => 'ABSENT']])->assertSuccessful();
    attClose($this, $counsellor->user, $session)->assertStatus(422)->assertJsonPath('message', CloseSessionAsNoShowAction::NOT_ALL_ABSENT);

    attRecord($this, $counsellor->user, $session, [['key' => $leaverKey, 'status' => 'ABSENT']])->assertSuccessful();
    expect(SessionAttendance::query()->where('user_id', $named->id)->value('status'))->toBe('ABSENT');
    attClose($this, $counsellor->user, $session)->assertSuccessful();

    // Someone who left before the session started isn't on it.
    $earlier = attSession($group, ['start_time' => now()->subMinutes(30), 'end_time' => now()->addMinutes(30)]);
    attRecord($this, $counsellor->user, $earlier, [['key' => $leaverKey, 'status' => 'ABSENT']])
        ->assertStatus(422)->assertJsonPath('message', RecordSessionAttendanceAction::UNKNOWN_PERSON);
});

test('a member who left after the session and rejoined is on its register once, under the same key', function () {
    [$counsellor, $group, $named, $anonymous] = attGroup();
    $session = attSession($group, ['start_time' => now()->subHours(3), 'end_time' => now()->subHours(2)]);
    foreach ([$named, $anonymous] as $member) {
        $group->users()->updateExistingPivot($member->id, ['created_at' => now()->subDay()]);
    }
    $key = attKeyOf($group, $named);
    attRecord($this, $counsellor->user, $session, [['key' => $key, 'status' => 'ABSENT']])->assertSuccessful();

    // Rejoining reuses the membership row (AddGroupTherapyMemberAction): left_at cleared, the key kept.
    $group->allMemberships()->updateExistingPivot($named->id, ['left_at' => now()->subHour()]);
    $group->allMemberships()->updateExistingPivot($named->id, ['left_at' => null]);
    expect(attKeyOf($group, $named))->toBe($key);

    $register = attRecord($this, $counsellor->user, $session, [['key' => $key, 'status' => 'PRESENT']])->assertSuccessful()->json('register');
    expect(collect($register)->pluck('key')->all())->toBe([$key, attKeyOf($group, $anonymous)])
        ->and(collect($register)->firstWhere('key', $key)['name'])->toBe('att Named Member')
        ->and(SessionAttendance::query()->where('user_id', $named->id)->sole()->status)->toBe('PRESENT');
});

test('an inactive (not active) counsellor of the group can\'t mark attendance or see the register', function () {
    [, $group] = attGroup();
    $session = attSession($group);
    $pending = attCounsellor();
    $group->counsellors()->attach($pending->id, ['state' => CounsellorGroupTherapyStateEnum::inactive->value, 'role' => 'NORMAL']);

    attRecord($this, $pending->user, $session, [['key' => GroupMemberKeys::CREATOR, 'status' => 'ABSENT']])
        ->assertStatus(422)->assertJsonPath('message', EnsureCanRecordSessionAttendanceAction::NOT_ALLOWED);
    attRead($this, $pending->user, $session)->assertStatus(422);
});

test('a user-created group\'s creator is on the register as "creator"', function () {
    $creator = User::factory()->adult()->create();
    $counsellor = attCounsellor();
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $creator->id, 'allow_in_person' => true, 'payment_type' => 'FREE']);
    $group->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);
    $session = Session::factory()->create([
        'for_type' => GroupTherapy::class, 'for_id' => $group->id, 'addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id,
        'status' => 'PENDING', 'payment_type' => 'FREE', 'type' => 'IN_PERSON', 'start_time' => now()->subMinutes(5), 'end_time' => now()->addHour(),
    ]);

    attRecord($this, $counsellor->user, $session, [['key' => GroupMemberKeys::CREATOR, 'status' => 'PRESENT']])
        ->assertSuccessful()->assertJsonPath('register.0.key', 'creator');
    expect(SessionAttendance::query()->sole()->user_id)->toBe($creator->id);
});

test('when everyone was absent the counsellor closes the session as FAILED, with no invoice line', function () {
    [$counsellor, $group, $named, $anonymous] = attGroup();
    $session = attSession($group);
    $closeAs = fn () => attClose($this, $counsellor->user, $session);

    $closeAs()->assertStatus(422)->assertJsonPath('message', CloseSessionAsNoShowAction::NOT_ENDED);
    $this->travel(2)->hours();
    $closeAs()->assertStatus(422)->assertJsonPath('message', CloseSessionAsNoShowAction::NOT_ALL_ABSENT);
    attRecord($this, $counsellor->user, $session, [['key' => attKeyOf($group, $named), 'status' => 'ABSENT']])->assertSuccessful();
    $closeAs()->assertStatus(422)->assertJsonPath('message', CloseSessionAsNoShowAction::NOT_ALL_ABSENT); // one unmarked
    attRecord($this, $counsellor->user, $session, [['key' => attKeyOf($group, $anonymous), 'status' => 'PRESENT']])->assertSuccessful();
    $closeAs()->assertStatus(422);

    attRecord($this, $counsellor->user, $session, [['key' => attKeyOf($group, $anonymous), 'status' => 'ABSENT']])->assertSuccessful();
    $closeAs()->assertSuccessful()->assertJsonPath('session.status', SessionStatusEnum::failed->value);

    $session->refresh();
    expect($session->status)->toBe(SessionStatusEnum::failed->value)
        ->and($session->ended_at)->not->toBeNull()
        ->and(OrganizationInvoiceLine::query()->where('session_id', $session->id)->exists())->toBeFalse();

    // Closed: final.
    $closeAs()->assertStatus(422)->assertJsonPath('message', EnsureCanRecordSessionAttendanceAction::WINDOW_CLOSED);
});

test('a session that started, was held or billed is never closed as a no-show; a closed one\'s register is final', function () {
    [, $counsellor, $therapy] = attTherapy();
    $past = ['start_time' => now()->subHours(3), 'end_time' => now()->subHours(2)];

    foreach ([SessionStatusEnum::held, SessionStatusEnum::in_session, SessionStatusEnum::held_confirmation] as $status) {
        $session = attSession($therapy, ['status' => $status->value, ...$past]);
        attRecord($this, $counsellor->user, $session, [['key' => 'client', 'status' => 'ABSENT']])->assertSuccessful(); // still marked

        attClose($this, $counsellor->user, $session)->assertStatus(422)->assertJsonPath('message', CloseSessionAsNoShowAction::NOT_CLOSABLE);
        expect($session->fresh()->status)->toBe($status->value)->and($session->fresh()->ended_at)->toBeNull();
    }

    foreach ([SessionStatusEnum::failed, SessionStatusEnum::abandoned] as $status) {
        $session = attSession($therapy, ['status' => $status->value, ...$past]);
        attRecord($this, $counsellor->user, $session, [['key' => 'client', 'status' => 'ABSENT']])
            ->assertStatus(422)->assertJsonPath('message', EnsureCanRecordSessionAttendanceAction::WINDOW_CLOSED);
        attClose($this, $counsellor->user, $session)->assertStatus(422);
    }
});

test('a start asked for by the client blocks a no-show close; one asked for by the counsellor doesn\'t', function () {
    [$client, $counsellor, $therapy] = attTherapy();
    $past = ['status' => SessionStatusEnum::in_session_confirmation->value, 'start_time' => now()->subHours(3), 'end_time' => now()->subHours(2)];

    $askedByClient = attSession($therapy, $past);
    $askedByClient->updatedby()->associate($client)->save();
    $askedByCounsellor = attSession($therapy, $past);
    $askedByCounsellor->updatedby()->associate($counsellor)->save();
    $noAsker = attSession($therapy, $past);

    foreach ([$askedByClient, $askedByCounsellor, $noAsker] as $session) {
        attRecord($this, $counsellor->user, $session, [['key' => 'client', 'status' => 'ABSENT']])->assertSuccessful();
    }

    attClose($this, $counsellor->user, $askedByClient)->assertStatus(422)->assertJsonPath('message', CloseSessionAsNoShowAction::NOT_CLOSABLE);
    attClose($this, $counsellor->user, $noAsker)->assertStatus(422)->assertJsonPath('message', CloseSessionAsNoShowAction::NOT_CLOSABLE);
    attClose($this, $counsellor->user, $askedByCounsellor)->assertSuccessful();
    expect($askedByClient->fresh()->status)->toBe(SessionStatusEnum::in_session_confirmation->value)
        ->and($askedByCounsellor->fresh()->status)->toBe(SessionStatusEnum::failed->value);
});

test('a write announces the session by its id only', function () {
    Event::fake([SessionUpdatedEvent::class]);
    [, $counsellor, $therapy] = attTherapy();
    $session = attSession($therapy);

    attRecord($this, $counsellor->user, $session, [['key' => 'client', 'status' => 'ABSENT']])->assertSuccessful();
    $this->travel(2)->hours();
    attClose($this, $counsellor->user, $session)->assertSuccessful();

    Event::assertDispatched(SessionUpdatedEvent::class, 2);
    Event::assertDispatched(SessionUpdatedEvent::class, fn (SessionUpdatedEvent $event) => $event->broadcastWith() === ['sessionId' => $session->id]);
});

test('"Your data" counts and gives the person\'s own marks, never the place or who marked them', function () {
    [$client, $counsellor, $therapy] = attTherapy();
    $session = attSession($therapy);
    attRecord($this, $counsellor->user, $session, [['key' => 'client', 'status' => 'PRESENT']])->assertSuccessful();

    expect(GetYourDataAction::new()->execute($client)['attendance'])->toBe(['marked' => 1])
        ->and(GetYourDataAction::new()->execute($counsellor->user)['attendance'])->toBe(['marked' => 0]);

    $copy = json_decode(implode('', iterator_to_array(ExportYourDataAction::new()->execute($client), false)), true, flags: JSON_THROW_ON_ERROR);
    expect($copy['attendance'])->toBe([[
        'sessionId' => $session->id, 'status' => 'PRESENT',
        'markedAt' => SessionAttendance::query()->sole()->marked_at->toIso8601String(),
    ]])->and(json_encode($copy['attendance']))->not->toContain('att Room 3');
});

test('the register request is validated: a list of key and status, each key once', function () {
    [, $counsellor, $therapy] = attTherapy();
    $session = attSession($therapy);

    attRecord($this, $counsellor->user, $session, [])->assertJsonValidationErrors('attendance');
    attRecord($this, $counsellor->user, $session, [['key' => 'client', 'status' => 'LATE']])->assertJsonValidationErrors('attendance.0.status');
    attRecord($this, $counsellor->user, $session, [['key' => 'client', 'status' => 'PRESENT'], ['key' => 'client', 'status' => 'ABSENT']])->assertJsonValidationErrors('attendance.0.key');
    attRecord($this, $counsellor->user, $session, [['key' => 'client', 'status' => 'PRESENT', 'userId' => 1]])->assertJsonValidationErrors('attendance.0');
    expect(SessionAttendance::query()->count())->toBe(0);
});

test('an administrator\'s read of a client\'s own 1:1 register shows in their "Your data" team access', function () {
    [$client, , $therapy] = attTherapy();
    $session = attSession($therapy);

    attRead($this, attAdmin(), $session)->assertOk();

    expect(collect(GetYourDataAction::new()->execute($client)['teamAccess']['latest'])->pluck('resource')->all())
        ->toContain(AdminContentAccess::SESSION_ATTENDANCE);
});

test('the session fetch costs the same number of queries for a group of 2 or 6 members', function () {
    $count = function (int $extra) {
        [$counsellor, $group] = attGroup();
        for ($i = 1; $i <= $extra; $i++) {
            $group->users()->attach(User::factory()->adult()->create()->id, ['anonymous' => $i % 2 === 0, 'background_story' => 'x']);
        }
        $session = attSession($group);
        $this->actingAs($counsellor->user);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $json = $this->getJson(route('api.sessions.show', ['sessionId' => $session->id]))->assertOk()->json();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        expect($json['data']['attendance'] ?? $json['attendance'])->toHaveCount(2 + $extra);

        return $queries;
    };

    expect($count(4))->toBe($count(0));
});

test('an in-person session in a list or the calendar carries no attendance', function () {
    [$client, $counsellor, $therapy] = attTherapy();
    $session = attSession($therapy);
    attRecord($this, $counsellor->user, $session, [['key' => 'client', 'status' => 'PRESENT']])->assertSuccessful();

    $unwrap = fn (array $json) => $json['data'] ?? $json;
    $listed = $unwrap($this->actingAs($client)->getJson(route('api.sessions.get', ['therapyId' => $therapy->id]))->assertOk()->json());
    $calendar = $unwrap($unwrap($this->actingAs($counsellor->user)->getJson(route('api.sessions.calendar', [
        'startDate' => now()->startOfWeek()->toDateTimeString(), 'endDate' => now()->addWeeks(2)->endOfWeek()->toDateTimeString(),
    ]))->assertOk()->json('sessions')));

    foreach ([collect($listed)->firstWhere('id', $session->id), collect($calendar)->firstWhere('id', $session->id)] as $row) {
        expect($row)->not->toBeNull()->not->toHaveKey('attendance')->not->toHaveKey('viewerAttendance');
    }
});

test('the scheduled fail leaves an in-person session alone until its attendance window has closed', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $therapy = Therapy::factory()->create(['allow_in_person' => true, 'counsellor_id' => $counsellor->id, 'addedby_type' => User::class, 'addedby_id' => User::factory()->adult()->create()->id]);
    $inPerson = Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id, 'type' => 'IN_PERSON', 'status' => 'PENDING', 'start_time' => now()->subHours(3), 'end_time' => now()->subHours(2), 'latitude' => 5.6, 'longitude' => -0.18, 'landmark' => 'att room']);
    $online = Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id, 'type' => 'ONLINE', 'status' => 'PENDING', 'start_time' => now()->subHours(3), 'end_time' => now()->subHours(2)]);

    FailUnheldSessionsAction::new()->execute();
    expect($inPerson->fresh()->status)->toBe('PENDING')->and($online->fresh()->status)->toBe('FAILED');

    $this->travel((int) config('limits.attendance_window_hours') + 1)->hours();
    FailUnheldSessionsAction::new()->execute();
    expect($inPerson->fresh()->status)->toBe('FAILED');
});
