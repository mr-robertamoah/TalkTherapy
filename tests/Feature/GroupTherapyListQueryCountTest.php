<?php

use App\Actions\GroupTherapy\PreloadGroupTherapyListAction;
use App\Enums\SessionStatusEnum;
use App\Enums\TherapyStatusEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\Session;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

// SCRUM-386: GroupTherapyMiniResource lists load what each row reads once per page
// (PreloadGroupTherapyListAction), so a list costs the same number of queries for 1 group as for 5.
// Helpers are file-local and gtlq-prefixed (--parallel).

function gtlqCounsellor(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id, 'verified_at' => now()]);
}

// A running, public, non-anonymous group with an active counsellor, two members (one anonymous) and
// one held and one pending session -- everything GroupTherapyMiniResource reads per row.
function gtlqGroup(User|Counsellor $addedby, ?Counsellor $counsellor = null, array $attributes = []): GroupTherapy
{
    $group = GroupTherapy::factory()->create(array_merge([
        'addedby_type' => $addedby::class, 'addedby_id' => $addedby->id, 'public' => true, 'anonymous' => false,
        'status' => TherapyStatusEnum::in_session->value,
    ], $attributes));

    $group->counsellors()->attach(($counsellor ?? gtlqCounsellor())->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $group->allMemberships()->attach(User::factory()->adult()->create()->id, ['anonymous' => false, 'was_minor_at_join' => false]);
    $group->allMemberships()->attach(User::factory()->adult()->create()->id, ['anonymous' => true, 'was_minor_at_join' => false]);

    foreach ([SessionStatusEnum::held, SessionStatusEnum::pending] as $status) {
        Session::factory()->create([
            'for_id' => $group->id, 'for_type' => GroupTherapy::class, 'status' => $status->value,
            'addedby_type' => User::class, 'addedby_id' => $group->addedby_type === User::class ? $group->addedby_id : $addedby->user_id,
            'start_time' => now()->addDays(2), 'end_time' => now()->addDays(2)->addHour(),
        ]);
    }

    return $group;
}

function gtlqQueryCount(callable $request): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $request();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();
    DB::flushQueryLog();

    return $count;
}

// Counts the queries for a list with 1 group, then again once $addGroup has added 4 more. A first,
// uncounted request warms what middleware records once per visitor.
function gtlqCountsFor(callable $request, callable $addGroup): array
{
    $addGroup();
    $request();
    $one = gtlqQueryCount($request);

    foreach (range(1, 4) as $ignored) {
        $addGroup();
    }

    return [$one, gtlqQueryCount($request)];
}

test('the random (public) group list costs the same queries for 1 group as for 5', function () {
    $viewer = User::factory()->adult()->create();

    [$one, $five] = gtlqCountsFor(
        fn () => $this->actingAs($viewer)->getJson(route('api.group.therapies.random'))->assertOk(),
        fn () => gtlqGroup(User::factory()->adult()->create()),
    );

    expect($five)->toBe($one);
});

// A guest: a signed-in user's random list leaves out groups created through a counsellor profile.
test('the random list returns the same row data as before preloading', function () {
    $creator = User::factory()->adult()->create(['firstName' => 'Gtlq', 'lastName' => 'Creator']);
    $group = gtlqGroup($creator);
    $byCounsellor = gtlqGroup(gtlqCounsellor());
    $anonymous = gtlqGroup(User::factory()->adult()->create(), attributes: ['anonymous' => true]);

    $rows = collect($this->getJson(route('api.group.therapies.random'))->assertOk()->json('data'))->keyBy('id');

    expect($rows[$group->id])
        ->counsellorsCount->toBe(1)
        ->sessionsHeld->toBe(1)
        ->userId->toBe($creator->id)
        ->and($rows[$group->id]['addedby']['fullName'])->toBe($creator->name)
        ->and($rows[$byCounsellor->id]['addedby']['isCounsellor'])->toBeTrue()
        ->and($rows[$byCounsellor->id]['userId'])->toBe($byCounsellor->addedby->user_id)
        ->and($rows[$anonymous->id]['userId'])->toBeNull()
        ->and($rows[$anonymous->id]['addedby']['id'])->toBeNull();
});

test('the user\'s own group list costs the same queries for 1 group as for 5', function () {
    $viewer = User::factory()->adult()->create();

    [$one, $five] = gtlqCountsFor(
        fn () => $this->actingAs($viewer)->getJson(route('api.group.therapies.user'))->assertOk(),
        fn () => gtlqGroup($viewer),
    );

    expect($five)->toBe($one);
});

test('the ward group list costs the same queries for 1 group as for 5', function () {
    $guardian = User::factory()->adult()->create();

    [$one, $five] = gtlqCountsFor(
        fn () => $this->actingAs($guardian)->getJson(route('api.group.therapies.ward'))->assertOk(),
        function () use ($guardian) {
            $ward = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);
            Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $ward->id, 'ward_was_minor_at_creation' => true]);
            gtlqGroup($ward, attributes: ['client_was_minor_at_creation' => true]);
        },
    );

    expect($five)->toBe($one);
});

test('the counsellor group list costs the same queries for 1 group as for 5', function () {
    $counsellor = gtlqCounsellor();

    [$one, $five] = gtlqCountsFor(
        fn () => $this->actingAs($counsellor->user)->getJson(route('api.group.therapies.counsellor'))->assertOk(),
        fn () => gtlqGroup(User::factory()->adult()->create(), $counsellor),
    );

    expect($five)->toBe($one);
});

test('the counsellor calendar costs the same queries for sessions in 1 group as in 5', function () {
    $counsellor = gtlqCounsellor();

    [$one, $five] = gtlqCountsFor(
        fn () => $this->actingAs($counsellor->user)->getJson(route('api.sessions.calendar', [
            'startDate' => now()->startOfWeek()->toDateTimeString(),
            'endDate' => now()->addWeeks(2)->endOfWeek()->toDateTimeString(),
        ]))->assertOk(),
        fn () => gtlqGroup(User::factory()->adult()->create(), $counsellor),
    );

    expect($five)->toBe($one);
});

// SCRUM-386 review: a list preloads only the ACTIVE counsellors' ids (`activeCounsellorLinks`), for
// isCounsellor(); the full `counsellors` relation is left alone.
test('a preloaded list answers isCounsellor() from its active counsellors only', function () {
    $active = gtlqCounsellor();
    // INACTIVE: left the group, or removed from it -- still a pivot row.
    $inactive = gtlqCounsellor();
    $never = gtlqCounsellor();
    $group = gtlqGroup(User::factory()->adult()->create(), $active);
    $group->counsellors()->attach($inactive->id, ['state' => 'INACTIVE', 'role' => 'NORMAL']);

    $groups = GroupTherapy::query()->whereKey($group->id)->get();
    PreloadGroupTherapyListAction::new()->execute($groups);
    $loaded = $groups->first();

    expect($loaded->relationLoaded('activeCounsellorLinks'))->toBeTrue()
        ->and($loaded->relationLoaded('counsellors'))->toBeFalse();

    DB::flushQueryLog();
    DB::enableQueryLog();
    expect($loaded->isCounsellor($active))->toBeTrue()
        ->and($loaded->isCounsellor($inactive))->toBeFalse()
        ->and($loaded->isCounsellor($never))->toBeFalse();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Read from the preload, not queried.
    expect($queries)->toBe(0);
});

test('reading counsellors after a preload gives full models, every state', function () {
    $active = gtlqCounsellor();
    $group = gtlqGroup(User::factory()->adult()->create(), $active);
    $group->counsellors()->attach(gtlqCounsellor()->id, ['state' => 'INACTIVE', 'role' => 'NORMAL']);

    $groups = GroupTherapy::query()->whereKey($group->id)->get();
    PreloadGroupTherapyListAction::new()->execute($groups);
    $counsellors = $groups->first()->counsellors;

    expect($counsellors)->toHaveCount(2)
        ->and($counsellors->firstWhere('id', $active->id)->name)->toBe($active->name)
        ->and($counsellors->firstWhere('id', $active->id)->pivot->state)->toBe('ACTIVE');
});

test('a counsellors relation the caller loaded, however filtered, is never read by isCounsellor()', function () {
    $active = gtlqCounsellor();
    $group = gtlqGroup(User::factory()->adult()->create(), $active);

    $group->setRelation('counsellors', new Collection);

    expect($group->isCounsellor($active))->toBeTrue();
});
