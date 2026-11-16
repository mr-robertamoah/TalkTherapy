<?php

use App\Actions\Session\FailUnheldSessionsAction;
use App\Actions\Session\NotifyParticipantsOfStartingSessionsAction;
use App\Actions\Therapy\DeleteTherapyAction;
use App\DTOs\GroupTherapyDTO;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Enums\SessionStatusEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\OrganizationInvoiceLine;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\SessionDueNotification;
use App\Notifications\SessionFailedNotification;
use App\Services\SessionService;
use Illuminate\Support\Facades\Notification;

// SCRUM-448: ending or deleting a therapy closes its open sessions (FAILED if never started, else ABANDONED, never
// HELD), with ended_at, and never turns a failed or abandoned session into a held one. SCRUM-449: group sessions resolve their participants, so their
// "starting" and "failed" notices go out. SCRUM-450: only a session that never started can be marked failed.

beforeEach(fn () => Notification::fake());

function slfCounsellor(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
}

function slfTherapy(): array
{
    $client = User::factory()->adult()->create();
    $counsellor = slfCounsellor();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id, 'payment_type' => 'FREE', 'public' => false]);

    return [$client, $counsellor, $therapy];
}

function slfSession(Therapy|GroupTherapy $for, array $attributes = []): Session
{
    return Session::factory()->create([
        'for_type' => $for::class, 'for_id' => $for->id, 'addedby_type' => Counsellor::class,
        'addedby_id' => $for instanceof Therapy ? $for->counsellor_id : $for->addedby_id,
        'status' => SessionStatusEnum::pending->value, 'payment_type' => 'FREE', 'type' => 'ONLINE',
        'start_time' => now()->subHours(2), 'end_time' => now()->subHour(), ...$attributes,
    ]);
}

// A counsellor-created group: its active counsellor, an inactive one, an adult member, a former member, a minor with
// a guardian and a minor without one.
function slfGroup(): array
{
    $counsellor = slfCounsellor();
    $inactive = slfCounsellor();
    $group = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id, 'payment_type' => 'FREE', 'anonymous' => false, 'public' => false]);
    $group->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);
    $group->counsellors()->attach($inactive->id, ['state' => CounsellorGroupTherapyStateEnum::inactive->value, 'role' => 'NORMAL']);

    $member = User::factory()->adult()->create();
    $former = User::factory()->adult()->create();
    $coveredMinor = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);
    Guardianship::create(['guardian_id' => User::factory()->adult()->create()->id, 'ward_id' => $coveredMinor->id]);
    $pausedMinor = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);

    foreach ([$member, $former, $coveredMinor, $pausedMinor] as $user) {
        $group->users()->attach($user->id, ['anonymous' => false, 'background_story' => 'x']);
    }
    $group->allMemberships()->updateExistingPivot($former->id, ['left_at' => now()]);

    return compact('counsellor', 'inactive', 'group', 'member', 'former', 'coveredMinor', 'pausedMinor');
}

// SCRUM-448

test('ending a therapy fails sessions that never started and leaves finished ones as they were', function () {
    [$client, , $therapy] = slfTherapy();
    $pending = slfSession($therapy);
    $unconfirmedStart = slfSession($therapy, ['status' => SessionStatusEnum::in_session_confirmation->value]);
    $failed = slfSession($therapy, ['status' => SessionStatusEnum::failed->value]);
    $abandoned = slfSession($therapy, ['status' => SessionStatusEnum::abandoned->value]);

    $therapy->endSessions();

    expect($pending->fresh()->status)->toBe(SessionStatusEnum::failed->value)
        ->and($pending->fresh()->ended_at)->not->toBeNull()
        ->and($unconfirmedStart->fresh()->status)->toBe(SessionStatusEnum::failed->value)
        ->and($failed->fresh()->status)->toBe(SessionStatusEnum::failed->value)
        ->and($abandoned->fresh()->status)->toBe(SessionStatusEnum::abandoned->value);
});

test('ending a therapy abandons sessions under way and never finishes one as held', function () {
    [, , $therapy] = slfTherapy();
    $pastEnd = slfSession($therapy, ['status' => SessionStatusEnum::in_session->value]);
    // One side's "it was held", never confirmed -- ending the therapy mustn't confirm it (or bill it) for them.
    $oneSidedHeld = slfSession($therapy, ['status' => SessionStatusEnum::held_confirmation->value]);
    $midway = slfSession($therapy, ['status' => SessionStatusEnum::in_session->value, 'start_time' => now()->subMinutes(10), 'end_time' => now()->addMinutes(50)]);
    $future = slfSession($therapy, ['start_time' => now()->addDay(), 'end_time' => now()->addDay()->addHour()]);

    $therapy->endSessions();

    expect($pastEnd->fresh()->status)->toBe(SessionStatusEnum::abandoned->value)
        ->and($pastEnd->fresh()->ended_at)->not->toBeNull()
        ->and($oneSidedHeld->fresh()->status)->toBe(SessionStatusEnum::abandoned->value)
        ->and($midway->fresh()->status)->toBe(SessionStatusEnum::abandoned->value)
        ->and($future->fresh()->status)->toBe(SessionStatusEnum::failed->value)
        ->and(OrganizationInvoiceLine::count())->toBe(0);
});

test('deleting a group closes its sessions the same way', function () {
    $g = slfGroup();
    $held = slfSession($g['group'], ['status' => SessionStatusEnum::held->value]);
    $oneSidedHeld = slfSession($g['group'], ['status' => SessionStatusEnum::held_confirmation->value]);
    $pending = slfSession($g['group']);

    DeleteTherapyAction::new()->execute(GroupTherapyDTO::new()->fromArray(['groupTherapy' => $g['group']]));

    expect($held->fresh()->status)->toBe(SessionStatusEnum::held->value)
        ->and($oneSidedHeld->fresh()->status)->toBe(SessionStatusEnum::abandoned->value)
        ->and($pending->fresh()->status)->toBe(SessionStatusEnum::failed->value);
});

test('ending a therapy keeps the ended_at a session already had', function () {
    [, , $therapy] = slfTherapy();
    $endedAt = now()->subMinutes(70)->startOfSecond();
    $session = slfSession($therapy, ['status' => SessionStatusEnum::held_confirmation->value, 'ended_at' => $endedAt]);

    $therapy->endSessions();

    expect($session->fresh()->ended_at->equalTo($endedAt))->toBeTrue();
});

test('the scheduler marks a session it fails as ended', function () {
    [, , $therapy] = slfTherapy();
    $session = slfSession($therapy);

    FailUnheldSessionsAction::new()->execute();

    expect($session->fresh()->status)->toBe(SessionStatusEnum::failed->value)
        ->and($session->fresh()->ended_at)->not->toBeNull();
});

// SCRUM-449

test('a group session\'s participants are its active counsellors and current members who may take part', function () {
    $g = slfGroup();
    $session = slfSession($g['group']);

    $ids = collect($session->fresh()->users)->pluck('id');

    expect($ids->sort()->values()->all())->toBe(collect([$g['counsellor']->user_id, $g['member']->id, $g['coveredMinor']->id])->sort()->values()->all());
});

test('a group session\'s starting notice goes to its participants', function () {
    $g = slfGroup();
    slfSession($g['group'], ['start_time' => now()->addMinutes(10), 'end_time' => now()->addMinutes(70)]);

    NotifyParticipantsOfStartingSessionsAction::new()->execute();

    Notification::assertSentTo([$g['counsellor']->user, $g['member'], $g['coveredMinor']], SessionDueNotification::class);
    Notification::assertNotSentTo([$g['inactive']->user, $g['former'], $g['pausedMinor']], SessionDueNotification::class);
});

test('a group session\'s failed notice goes to its participants', function () {
    $g = slfGroup();
    slfSession($g['group']);

    FailUnheldSessionsAction::new()->execute();

    Notification::assertSentTo([$g['counsellor']->user, $g['member'], $g['coveredMinor']], SessionFailedNotification::class);
    Notification::assertNotSentTo([$g['inactive']->user, $g['former'], $g['pausedMinor']], SessionFailedNotification::class);
});

test('a user-created group session includes its creator', function () {
    $creator = User::factory()->adult()->create();
    $counsellor = slfCounsellor();
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $creator->id, 'payment_type' => 'FREE', 'anonymous' => false, 'public' => false]);
    $group->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);
    $session = slfSession($group, ['addedby_id' => $counsellor->id]);

    expect(collect($session->fresh()->users)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$creator->id, $counsellor->user_id])->sort()->values()->all());
});

test('a group whose creator\'s counsellor profile was deleted doesn\'t tell that creator', function () {
    $g = slfGroup();
    $session = slfSession($g['group']);
    $g['counsellor']->delete();

    expect(collect($session->fresh()->users)->pluck('id'))->not->toContain($g['counsellor']->user_id);
});

test('the guardian pause applies to a group\'s clients, not its counsellors', function () {
    $g = slfGroup();
    $g['counsellor']->user->update(['dob' => null]);
    $session = slfSession($g['group']);

    expect(collect($session->fresh()->users)->pluck('id'))->toContain($g['counsellor']->user_id);
});

test('the failed notice is emailed only to a verified address', function () {
    $verified = User::factory()->adult()->create();
    $unverified = User::factory()->adult()->unverified()->create();
    $notification = new SessionFailedNotification(Session::factory()->make());

    expect($notification->via($verified))->toBe(['mail'])
        ->and($notification->via($unverified))->toBe([]);
});

// SCRUM-450

test('a participant can mark a session that never started as failed', function (string $status) {
    [$client, , $therapy] = slfTherapy();
    $session = slfSession($therapy, ['status' => $status]);

    $this->actingAs($client)->postJson(route('api.sessions.fail', $session->id))->assertSuccessful();

    expect($session->fresh()->status)->toBe(SessionStatusEnum::failed->value);
})->with([SessionStatusEnum::pending->value, SessionStatusEnum::in_session_confirmation->value]);

test('nobody can mark a session that started or finished as failed', function (string $status) {
    [$client, $counsellor, $therapy] = slfTherapy();
    $session = slfSession($therapy, ['status' => $status]);

    foreach ([$client, $counsellor->user] as $user) {
        $this->actingAs($user)->postJson(route('api.sessions.fail', $session->id))
            ->assertStatus(422)
            ->assertJsonFragment(['message' => SessionService::CANNOT_FAIL]);
    }

    expect($session->fresh()->status)->toBe($status);
})->with([
    SessionStatusEnum::held->value,
    SessionStatusEnum::abandoned->value,
    SessionStatusEnum::in_session->value,
    SessionStatusEnum::held_confirmation->value,
]);
