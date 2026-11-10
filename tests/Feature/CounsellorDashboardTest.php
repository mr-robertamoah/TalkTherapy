<?php

use App\Actions\Request\EnsureUserCanRespondToRequestAction;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\SessionStatusEnum;
use App\Enums\TherapyStatusEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Request;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// SCRUM-391: a counsellor's own dashboard. Helpers are file-local and cdb-prefixed (--parallel).

function cdbCounsellor(array $state = []): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id, ...$state]);
}

function cdbDashboard($test, Counsellor $counsellor): array
{
    return $test->actingAs($counsellor->user)->get(route('counsellor.dashboard'))->assertOk()->viewData('page')['props']['dashboard'];
}

function cdbRequest(string $type, $from, $to, $for): Request
{
    $request = new Request(['type' => $type, 'data' => [], 'status' => RequestStatusEnum::pending->value]);
    $request->from()->associate($from);
    $request->to()->associate($to);
    $request->for()->associate($for);
    $request->save();

    return $request;
}

test('only someone with a counsellor profile sees a dashboard, and the address isn\'t read as a counsellor id', function () {
    $this->get(route('counsellor.dashboard'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->adult()->create())->get(route('counsellor.dashboard'))->assertRedirect(route('home'));

    expect(route('counsellor.dashboard'))->toEndWith('/counsellor/dashboard');
    cdbDashboard($this, cdbCounsellor());
});

test('needs your action lists what waits on them -- addressed to their profile, or to their account about a group they created', function () {
    $counsellor = cdbCounsellor();
    $client = User::factory()->adult()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => null]);
    $invite = cdbRequest(RequestTypeEnum::therapy->value, $client, $counsellor, $therapy);
    $theirGroup = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id]);
    $membership = cdbRequest(RequestTypeEnum::groupTherapyMembership->value, $client, $counsellor->user, $theirGroup);
    // Not theirs to answer: one they sent, and one to someone else.
    cdbRequest(RequestTypeEnum::therapy->value, $counsellor, $client, Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id]));
    cdbRequest(RequestTypeEnum::therapy->value, $client, cdbCounsellor(), $therapy);

    $block = cdbDashboard($this, $counsellor)['needsAction'];

    expect($block['total'])->toBe(2)
        ->and(collect($block['items'])->pluck('id')->sort()->values()->all())->toBe(collect([$invite->id, $membership->id])->sort()->values()->all());

    // A dashboard view, not the authority: everything it lists is something they may answer.
    $respond = EnsureUserCanRespondToRequestAction::new();
    Request::query()->awaitingCounsellor($counsellor)->get()
        ->each(fn (Request $request) => expect($respond->userCanRespond($counsellor->user, $request))->toBeTrue());
});

test('current therapies and groups are only active ones; the next week\'s sessions are listed', function () {
    $counsellor = cdbCounsellor();
    $client = User::factory()->adult()->create();
    $active = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id, 'status' => TherapyStatusEnum::in_session->value]);
    Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id, 'status' => TherapyStatusEnum::ended->value]);
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'status' => TherapyStatusEnum::in_session->value]);
    $group->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $left = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id]);
    $left->counsellors()->attach($counsellor->id, ['state' => 'INACTIVE', 'role' => 'NORMAL']);

    $soon = Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $active->id, 'addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id,
        'status' => SessionStatusEnum::pending->value, 'start_time' => now()->addDays(2), 'end_time' => now()->addDays(2)->addHour()]);
    Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $active->id, 'addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id,
        'status' => SessionStatusEnum::pending->value, 'start_time' => now()->addDays(10), 'end_time' => now()->addDays(10)->addHour()]);

    $dashboard = cdbDashboard($this, $counsellor);

    expect(collect($dashboard['current']['therapies']['items'])->pluck('id')->all())->toBe([$active->id])
        ->and(collect($dashboard['current']['groups']['items'])->pluck('id')->all())->toBe([$group->id])
        ->and(collect($dashboard['upcomingSessions']['items'])->pluck('id')->all())->toBe([$soon->id]);
});

test('therapies you could help need a counsellor, and are only offered while their verification is current', function () {
    $counsellor = cdbCounsellor();
    $client = User::factory()->adult()->create();
    $needs = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'public' => true, 'counsellor_id' => null, 'status' => TherapyStatusEnum::pending->value]);
    Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'public' => true, 'counsellor_id' => cdbCounsellor()->id]);
    $fullGroup = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'public' => true, 'max_counsellors' => 1]);
    $fullGroup->counsellors()->attach(cdbCounsellor()->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $openGroup = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'public' => true, 'max_counsellors' => 2]);

    $items = collect(cdbDashboard($this, $counsellor)['couldHelp']['items']);

    expect($items->map(fn ($item) => "{$item['type']}:{$item['id']}")->sort()->values()->all())->toBe(["group:{$openGroup->id}", "individual:{$needs->id}"])
        ->and($items->pluck('viewer.action')->unique()->all())->toBe(['offer']);

    $counsellor->forceFill(['verification_expires_at' => now()->subDay()])->save();
    expect(cdbDashboard($this, $counsellor->fresh())['couldHelp'])->toBeNull();
});

test('the dashboard\'s query count doesn\'t grow with how much there is', function () {
    $counsellor = cdbCounsellor();
    $count = function (int $rows) use ($counsellor) {
        foreach (range(1, $rows) as $i) {
            Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()->adult()->create()->id, 'counsellor_id' => $counsellor->id, 'status' => TherapyStatusEnum::in_session->value]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        cdbDashboard($this, $counsellor);

        return count(DB::getQueryLog());
    };

    $count(1);
    expect($count(4))->toBe($count(0));
});

test('a group can take a counsellor in the query exactly when the model says so', function () {
    $creator = cdbCounsellor();
    $alsoMember = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $creator->id, 'max_counsellors' => 2]);
    $alsoMember->counsellors()->attach($creator->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $goneCreator = cdbCounsellor();
    $creatorGone = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $goneCreator->id, 'max_counsellors' => 1]);
    $goneCreator->delete();
    $goneMember = cdbCounsellor();
    $memberGone = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()->adult()->create()->id, 'max_counsellors' => 1]);
    $memberGone->counsellors()->attach($goneMember->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $goneMember->delete();
    $full = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => cdbCounsellor()->id, 'max_counsellors' => 1]);
    $ended = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()->adult()->create()->id, 'max_counsellors' => 3, 'status' => TherapyStatusEnum::ended->value]);

    $groups = collect([$alsoMember, $creatorGone, $memberGone, $full, $ended]);
    $inQuery = GroupTherapy::query()->whereKey($groups->pluck('id'))->whereCanTakeCounsellor()->pluck('id')->sort()->values()->all();

    expect($inQuery)->toBe($groups->filter(fn ($group) => $group->fresh()->canTakeCounsellor())->pluck('id')->sort()->values()->all())
        ->and($inQuery)->toBe(collect([$alsoMember->id, $creatorGone->id, $memberGone->id])->sort()->values()->all());
});

test('an anonymous client stays anonymous on the dashboard, and private therapies are never offered', function () {
    $counsellor = cdbCounsellor();
    $client = User::factory()->adult()->create(['firstName' => 'Hiddenname']);
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => null, 'anonymous' => true, 'public' => false]);
    cdbRequest(RequestTypeEnum::therapy->value, $client, $counsellor, $therapy);

    $dashboard = cdbDashboard($this, $counsellor);

    expect($dashboard['needsAction']['items'][0]['from']['id'])->toBeNull()
        ->and(json_encode($dashboard))->not->toContain('Hiddenname')
        ->and(collect($dashboard['couldHelp']['items'])->pluck('id'))->not->toContain($therapy->id);
});
