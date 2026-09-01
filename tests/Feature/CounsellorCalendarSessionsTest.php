<?php

use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Enums\SessionStatusEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// SCRUM-212/TT-2.6a: a counsellor's own sessions aggregated across every Therapy + GroupTherapy
// they're currently assigned to, date-range bounded for a calendar week/month view.

function aCounsellorForCalendarRoute(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()]);
}

function aTherapyForCalendarRoute(Counsellor $counsellor, User $client, array $attributes = []): Therapy
{
    return Therapy::factory()->create(array_merge([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
    ], $attributes));
}

function aSessionForCalendarRoute($for, array $attributes = []): Session
{
    return Session::factory()->create(array_merge([
        'for_id' => $for->id,
        'for_type' => $for::class,
        'status' => SessionStatusEnum::pending->value,
        'start_time' => now()->addDays(2),
        'end_time' => now()->addDays(2)->addHour(),
    ], $attributes));
}

function calendarRangeQuery(): array
{
    return [
        'startDate' => now()->startOfWeek()->toDateTimeString(),
        'endDate' => now()->addWeeks(2)->endOfWeek()->toDateTimeString(),
    ];
}

test('a counsellor sees sessions from an individual therapy they are assigned to', function () {
    $counsellor = aCounsellorForCalendarRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForCalendarRoute($counsellor, $client);
    $session = aSessionForCalendarRoute($therapy, ['addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id]);

    $this->actingAs($counsellor->user)
        ->getJson(route('api.sessions.calendar', calendarRangeQuery()))
        ->assertOk()
        ->assertJsonPath('sessions.0.id', $session->id);
});

test('a counsellor sees sessions from a group therapy they are attached to via the pivot', function () {
    $counsellor = aCounsellorForCalendarRoute();
    $creator = User::factory()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
    ]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);
    $session = aSessionForCalendarRoute($groupTherapy, ['addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id]);

    $this->actingAs($counsellor->user)
        ->getJson(route('api.sessions.calendar', calendarRangeQuery()))
        ->assertOk()
        ->assertJsonPath('sessions.0.id', $session->id);
});

test('a counsellor sees group therapy sessions for a group therapy they created directly (Counsellor addedby)', function () {
    $counsellor = aCounsellorForCalendarRoute();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => Counsellor::class,
        'addedby_id' => $counsellor->id,
    ]);
    $session = aSessionForCalendarRoute($groupTherapy, ['addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id]);

    $this->actingAs($counsellor->user)
        ->getJson(route('api.sessions.calendar', calendarRangeQuery()))
        ->assertOk()
        ->assertJsonPath('sessions.0.id', $session->id);
});

test('two counsellors sharing a group therapy each independently see its sessions', function () {
    $counsellorOne = aCounsellorForCalendarRoute();
    $counsellorTwo = aCounsellorForCalendarRoute();
    $creator = User::factory()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
    ]);
    $groupTherapy->counsellors()->attach([$counsellorOne->id, $counsellorTwo->id], ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);
    $session = aSessionForCalendarRoute($groupTherapy, ['addedby_type' => Counsellor::class, 'addedby_id' => $counsellorOne->id]);

    $this->actingAs($counsellorOne->user)
        ->getJson(route('api.sessions.calendar', calendarRangeQuery()))
        ->assertOk()
        ->assertJsonPath('sessions.0.id', $session->id);

    $this->actingAs($counsellorTwo->user)
        ->getJson(route('api.sessions.calendar', calendarRangeQuery()))
        ->assertOk()
        ->assertJsonPath('sessions.0.id', $session->id);
});

test('a counsellor never sees another counsellor\'s sessions', function () {
    $counsellorA = aCounsellorForCalendarRoute();
    $counsellorB = aCounsellorForCalendarRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForCalendarRoute($counsellorB, $client);
    aSessionForCalendarRoute($therapy, ['addedby_type' => Counsellor::class, 'addedby_id' => $counsellorB->id]);

    $this->actingAs($counsellorA->user)
        ->getJson(route('api.sessions.calendar', calendarRangeQuery()))
        ->assertOk()
        ->assertJsonCount(0, 'sessions');
});

test('a counsellor with an inactive pivot state on a group therapy does not see its sessions', function () {
    $counsellor = aCounsellorForCalendarRoute();
    $creator = User::factory()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
    ]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::inactive->value, 'role' => 'NORMAL']);
    aSessionForCalendarRoute($groupTherapy);

    $this->actingAs($counsellor->user)
        ->getJson(route('api.sessions.calendar', calendarRangeQuery()))
        ->assertOk()
        ->assertJsonCount(0, 'sessions');
});

test('a session outside the requested date range is not returned', function () {
    $counsellor = aCounsellorForCalendarRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForCalendarRoute($counsellor, $client);
    aSessionForCalendarRoute($therapy, [
        'start_time' => now()->addMonths(3),
        'end_time' => now()->addMonths(3)->addHour(),
    ]);

    $this->actingAs($counsellor->user)
        ->getJson(route('api.sessions.calendar', calendarRangeQuery()))
        ->assertOk()
        ->assertJsonCount(0, 'sessions');
});

test('a session that spans into the requested range from before it is still returned', function () {
    $counsellor = aCounsellorForCalendarRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForCalendarRoute($counsellor, $client);
    $rangeStart = now()->startOfWeek();
    $session = aSessionForCalendarRoute($therapy, [
        'start_time' => $rangeStart->copy()->subHour(),
        'end_time' => $rangeStart->copy()->addMinutes(30),
    ]);

    $this->actingAs($counsellor->user)
        ->getJson(route('api.sessions.calendar', calendarRangeQuery()))
        ->assertOk()
        ->assertJsonPath('sessions.0.id', $session->id);
});

test('a non-counsellor cannot view a session calendar', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson(route('api.sessions.calendar', calendarRangeQuery()))
        ->assertStatus(422);
});

test('an unauthenticated request cannot view a session calendar', function () {
    $this->getJson(route('api.sessions.calendar', calendarRangeQuery()))
        ->assertUnauthorized();
});

test('startDate and endDate are required', function () {
    $counsellor = aCounsellorForCalendarRoute();

    $this->actingAs($counsellor->user)
        ->getJson(route('api.sessions.calendar'))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['startDate', 'endDate']);
});

test('the date range cannot span more than 93 days', function () {
    $counsellor = aCounsellorForCalendarRoute();

    $this->actingAs($counsellor->user)
        ->getJson(route('api.sessions.calendar', [
            'startDate' => now()->toDateTimeString(),
            'endDate' => now()->addDays(100)->toDateTimeString(),
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['endDate']);
});

test('a session for an anonymous therapy masks the client\'s identity on the calendar', function () {
    $counsellor = aCounsellorForCalendarRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForCalendarRoute($counsellor, $client, ['anonymous' => true]);
    aSessionForCalendarRoute($therapy, ['addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id]);

    $response = $this->actingAs($counsellor->user)
        ->getJson(route('api.sessions.calendar', calendarRangeQuery()))
        ->assertOk();

    expect($response->json('sessions.0.for.userId'))->toBeNull();
    expect(json_encode($response->json('sessions.0.for')))->not->toContain($client->name);
});

test('a session for an anonymous group therapy masks the addedby user\'s identity on the calendar', function () {
    // The two union legs are rendered through different mini resources (TherapyMiniResource vs.
    // GroupTherapyMiniResource) -- the individual-therapy case above doesn't exercise this one.
    $counsellor = aCounsellorForCalendarRoute();
    $creator = User::factory()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
        'anonymous' => true,
    ]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);
    aSessionForCalendarRoute($groupTherapy, ['addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id]);

    $response = $this->actingAs($counsellor->user)
        ->getJson(route('api.sessions.calendar', calendarRangeQuery()))
        ->assertOk();

    expect($response->json('sessions.0.for.userId'))->toBeNull();
    expect(json_encode($response->json('sessions.0.for')))->not->toContain($creator->name);
});

test('the calendar response labels individual vs group therapy sessions', function () {
    $counsellor = aCounsellorForCalendarRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForCalendarRoute($counsellor, $client);
    aSessionForCalendarRoute($therapy, ['addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id, 'name' => 'Individual Slot']);

    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => Counsellor::class,
        'addedby_id' => $counsellor->id,
    ]);
    aSessionForCalendarRoute($groupTherapy, ['addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id, 'name' => 'Group Slot']);

    $response = $this->actingAs($counsellor->user)
        ->getJson(route('api.sessions.calendar', calendarRangeQuery()))
        ->assertOk()
        ->assertJsonCount(2, 'sessions');

    $sessions = collect($response->json('sessions'));
    $individual = $sessions->firstWhere('name', 'Individual Slot');
    $group = $sessions->firstWhere('name', 'Group Slot');

    expect($individual['for']['id'])->toBe($therapy->id);
    expect($group['for']['id'])->toBe($groupTherapy->id);
});

test('the calendar aggregation is not N+1 across a growing number of sessions', function () {
    // Fixed number of parent Therapy/GroupTherapy rows, growing number of SESSIONS within them --
    // isolates per-SESSION N+1 in SessionResource's own fields (topics/cases/currentTopic/addedby)
    // from per-PARENT costs (sessionsHeld/counsellorsCount), both of which this ticket fixes: the
    // former via eager-loading in this Action, the latter by memoizing the two count accessors on
    // the shared Therapy/GroupTherapy instance Eloquent's morphTo hydration already reuses across
    // every sibling session (confirmed: $session1->for === $session2->for for the same parent).
    $counsellor = aCounsellorForCalendarRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForCalendarRoute($counsellor, $client);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => Counsellor::class,
        'addedby_id' => $counsellor->id,
    ]);

    // Warm up the one-time "visitor" tracking insert (WaitingForAlert/visitor middleware) so it
    // doesn't skew the comparison below -- it only fires on this user's first authenticated
    // request ever, which would otherwise make the very first queryCountFor() call cost one more
    // query than every subsequent one, for a reason unrelated to N+1.
    $this->actingAs($counsellor->user)->getJson(route('api.sessions.calendar', calendarRangeQuery()));

    $queryCountFor = function (int $count) use ($counsellor, $therapy, $groupTherapy) {
        foreach (range(1, $count) as $i) {
            aSessionForCalendarRoute($therapy, [
                'addedby_type' => Counsellor::class,
                'addedby_id' => $counsellor->id,
                'start_time' => now()->addDays(2)->addHours($i * 2),
                'end_time' => now()->addDays(2)->addHours($i * 2)->addMinutes(45),
            ]);
            aSessionForCalendarRoute($groupTherapy, [
                'addedby_type' => Counsellor::class,
                'addedby_id' => $counsellor->id,
                'start_time' => now()->addDays(2)->addHours($i * 2),
                'end_time' => now()->addDays(2)->addHours($i * 2)->addMinutes(45),
            ]);
        }

        $this->actingAs($counsellor->user);

        DB::enableQueryLog();
        $this->getJson(route('api.sessions.calendar', calendarRangeQuery()))->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        return $queryCount;
    };

    expect($queryCountFor(1))->toBe($queryCountFor(4));
});
