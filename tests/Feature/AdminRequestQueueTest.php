<?php

use App\Enums\AdministratorTypeEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Organization;
use App\Models\Request;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// SCRUM-387: Request::scopeAwaitingAdmin() -- the personal Requests list's admin branches
// (unchanged), the "Requests to resolve" queue built on it, and what each row may show and do.
// Helpers are file-local and arq-prefixed (--parallel).

function arqAdmin(): User
{
    return User::factory()->adult()->has(Administrator::factory()->state(['type' => AdministratorTypeEnum::normal->value]))->create();
}

function arqSuperAdmin(): User
{
    return User::factory()->adult()->has(Administrator::factory()->state(['type' => AdministratorTypeEnum::super->value]))->create();
}

function arqRequest(string $type, $from, $to, $for, array $attributes = []): Request
{
    $request = new Request(['type' => $type, 'data' => $attributes['data'] ?? [], 'status' => $attributes['status'] ?? RequestStatusEnum::pending->value]);
    $request->from()->associate($from);
    if ($to) {
        $request->to()->associate($to);
    }
    $request->for()->associate($for);
    $request->save();

    return $request;
}

// A 15-year-old with no guardian in a 1:1 with a counsellor, and a request for a safeguarding
// exception for them -- from that counsellor, or from $sender (another counsellor of theirs).
function arqSafeguarding(?Counsellor $counsellor = null, ?Counsellor $sender = null): array
{
    $minor = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);
    $counsellor ??= Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $minor->id, 'counsellor_id' => $counsellor->id,
        'status' => TherapyStatusEnum::in_session->value, 'anonymous' => false, 'client_was_minor_at_creation' => true,
    ]);
    $request = arqRequest(RequestTypeEnum::safeguardingException->value, $sender ?? $counsellor, null, $therapy, [
        'data' => ['reason' => 'Needs to keep going.', 'proposedEndsAt' => now()->addDays(10)->toJSON(), 'wardId' => $minor->id],
    ]);

    return compact('minor', 'counsellor', 'therapy', 'request');
}

// One pending request of each queue type, plus a dobChange that does have a guardian (not admin work).
function arqOneOfEach(User $firstSuper): array
{
    $person = User::factory()->adult()->create();
    $guardian = User::factory()->adult()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $organization = Organization::factory()->create();
    $paidFor = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $person->id]);
    $transaction = Transaction::factory()->create(['user_id' => $person->id, 'for_type' => Therapy::class, 'for_id' => $paidFor->id, 'status' => 'SUCCESS']);

    return [
        'counsellor' => arqRequest(RequestTypeEnum::counsellor->value, $counsellor, $firstSuper, $counsellor),
        'organization' => arqRequest(RequestTypeEnum::organization->value, $organization, $firstSuper, $organization),
        'ageVerification' => arqRequest(RequestTypeEnum::ageVerification->value, $person, null, $person, ['data' => ['attestation' => 'It is true.', 'attestedDob' => '1990-01-01']]),
        'dobChange' => arqRequest(RequestTypeEnum::dobChange->value, $person, null, $person, ['data' => ['newDob' => '2010-01-01', 'priorDob' => '1990-01-01']]),
        'dobChangeWithGuardian' => arqRequest(RequestTypeEnum::dobChange->value, $person, $guardian, $person, ['data' => ['newDob' => '2010-01-01', 'priorDob' => '1990-01-01']]),
        'refund' => arqRequest(RequestTypeEnum::refund->value, $person, null, $transaction, ['data' => ['reason' => 'No show.']]),
        'safeguarding' => arqSafeguarding()['request'],
    ];
}

function arqListedIds($response): array
{
    return collect($response->json('data'))->pluck('id')->sort()->values()->all();
}

function arqQueueIds($response): array
{
    return collect($response->viewData('page')['props']['requests']['data'])->pluck('id')->sort()->values()->all();
}

// One queue row (RequestResource) by request id.
function arqQueueRow($response, int $id): array
{
    return collect($response->viewData('page')['props']['requests']['data'])->firstWhere('id', $id);
}

function arqQueueType($response, string $type): ?array
{
    return collect($response->viewData('page')['props']['types'])->firstWhere('value', $type);
}

test('the personal Requests list still adds the same admin rows: null-to dobChange and age verification for admins, safeguarding for super admins', function () {
    $super = arqSuperAdmin();
    $admin = arqAdmin();
    $rows = arqOneOfEach($super);
    $plain = User::factory()->adult()->create();

    $adminList = arqListedIds($this->actingAs($admin)->getJson(route('requests.get', ['status' => 'PENDING'])));
    expect($adminList)->toBe(collect([$rows['ageVerification']->id, $rows['dobChange']->id])->sort()->values()->all());

    // The verification types reach the super admin they are addressed to (the `to` branch), and
    // the safeguarding request through the super-admin branch; never the refund (null to).
    $superList = arqListedIds($this->actingAs($super)->getJson(route('requests.get', ['status' => 'PENDING'])));
    expect($superList)->toBe(collect([
        $rows['counsellor']->id, $rows['organization']->id, $rows['ageVerification']->id, $rows['dobChange']->id, $rows['safeguarding']->id,
    ])->sort()->values()->all());

    expect(arqListedIds($this->actingAs($plain)->getJson(route('requests.get', ['status' => 'PENDING']))))->toBe([]);

    // The status filter still applies to the admin branches too.
    $rows['dobChange']->update(['status' => RequestStatusEnum::rejected->value]);
    expect(arqListedIds($this->actingAs($admin)->getJson(route('requests.get', ['status' => 'PENDING']))))->toBe([$rows['ageVerification']->id]);
    expect(arqListedIds($this->actingAs($admin)->getJson(route('requests.get', ['status' => 'REJECTED']))))->toBe([$rows['dobChange']->id]);
});

test('the queue lists every queue type for a super admin, and all but safeguarding for an ordinary admin -- organisation verification included', function () {
    $super = arqSuperAdmin();
    $admin = arqAdmin();
    $rows = arqOneOfEach($super);
    $queueTypes = fn (array $keys) => collect($keys)->map(fn ($key) => $rows[$key]->id)->sort()->values()->all();

    expect(arqQueueIds($this->actingAs($super)->get(route('administrator.requests'))))
        ->toBe($queueTypes(['counsellor', 'organization', 'ageVerification', 'dobChange', 'refund', 'safeguarding']));

    $response = $this->actingAs($admin)->get(route('administrator.requests'));
    expect(arqQueueIds($response))->toBe($queueTypes(['counsellor', 'organization', 'ageVerification', 'dobChange', 'refund']));
    $response->assertInertia(fn ($page) => $page
        ->where('filters', ['type' => null, 'status' => 'PENDING'])
        ->where('types', fn ($types) => ! collect($types)->contains('value', RequestTypeEnum::safeguardingException->value)));
    // Addressed to the super admin, but this admin may answer it (the user's decision).
    expect(arqQueueRow($response, $rows['organization']->id)['isRespondent'])->toBeTrue()
        ->and(arqQueueRow($response, $rows['counsellor']->id)['isRespondent'])->toBeTrue()
        ->and(arqQueueRow($response, $rows['refund']->id)['isRespondent'])->toBeTrue()
        // Each type's label and own page come from the registry (AdminRequestQueue).
        ->and(arqQueueType($response, RequestTypeEnum::counsellor->value)['page']['route'])->toBe('administrator.counsellor_verification')
        ->and(arqQueueType($response, RequestTypeEnum::refund->value)['page']['route'])->toBe('administrator.refund_requests')
        ->and(arqQueueType($response, RequestTypeEnum::organization->value)['page'])->toBeNull()
        ->and(arqQueueType($response, RequestTypeEnum::dobChange->value)['label'])->toBe('Date-of-birth change (no guardian)');
});

test('a type the viewer cannot filter by is ignored, so the list and the filter shown agree; an unknown status is refused', function () {
    $super = arqSuperAdmin();
    $rows = arqOneOfEach($super);
    $everything = collect(['counsellor', 'organization', 'ageVerification', 'dobChange', 'refund'])->map(fn ($key) => $rows[$key]->id)->sort()->values()->all();

    // Safeguarding, for an ordinary admin: still never listed -- the unfiltered queue, shown as such.
    foreach ([RequestTypeEnum::safeguardingException->value, RequestTypeEnum::therapy->value, 'NOT_A_TYPE'] as $type) {
        $response = $this->actingAs(arqAdmin())->get(route('administrator.requests', ['type' => $type]));
        expect(arqQueueIds($response))->toBe($everything);
        $response->assertInertia(fn ($page) => $page->where('filters.type', null));
    }

    $this->actingAs(arqAdmin())->get(route('administrator.requests', ['status' => 'NOPE']))
        ->assertSessionHasErrors('status');
});

test('the type and status filters, and answered rows show when', function () {
    $super = arqSuperAdmin();
    $rows = arqOneOfEach($super);
    $rows['refund']->update(['status' => RequestStatusEnum::rejected->value]);
    $rows['dobChange']->update(['status' => RequestStatusEnum::accepted->value]);

    $admin = arqAdmin();
    expect(arqQueueIds($this->actingAs($admin)->get(route('administrator.requests', ['type' => RequestTypeEnum::organization->value]))))
        ->toBe([$rows['organization']->id]);
    expect(arqQueueIds($this->actingAs($admin)->get(route('administrator.requests', ['type' => RequestTypeEnum::refund->value]))))
        ->toBe([]);

    $response = $this->actingAs($admin)->get(route('administrator.requests', ['type' => RequestTypeEnum::refund->value, 'status' => 'REJECTED']));
    expect(arqQueueIds($response))->toBe([$rows['refund']->id]);
    $response->assertInertia(fn ($page) => $page
        ->where("rows.{$rows['refund']->id}.respondedAt", fn ($at) => ! is_null($at)));

    $all = arqQueueIds($this->actingAs($admin)->get(route('administrator.requests', ['status' => 'all'])));
    expect($all)->toContain($rows['refund']->id, $rows['dobChange']->id, $rows['organization']->id)
        ->not->toContain($rows['dobChangeWithGuardian']->id, $rows['safeguarding']->id);
});

test('an admin\'s own age verification is listed with no buttons, marked for another admin', function () {
    $admin = arqAdmin();
    $own = arqRequest(RequestTypeEnum::ageVerification->value, $admin, null, $admin, ['data' => ['attestation' => 'Mine.', 'attestedDob' => '1990-01-01']]);

    $this->actingAs($admin)->get(route('administrator.requests'))->assertInertia(fn ($page) => $page
        ->where('requests.data.0.id', $own->id)
        ->where('requests.data.0.isRespondent', false)
        ->where("rows.{$own->id}.ownAgeVerification", true));

    $this->actingAs(arqAdmin())->get(route('administrator.requests'))->assertInertia(fn ($page) => $page
        ->where('requests.data.0.isRespondent', true)
        ->where("rows.{$own->id}.ownAgeVerification", false));
});

test('a super admin who counsels the minor sees only that a safeguarding request exists, with no buttons', function () {
    $involved = arqSuperAdmin();
    $involvedCounsellor = Counsellor::factory()->create(['user_id' => $involved->id]);
    // They counsel the minor; someone else sent the request.
    $case = arqSafeguarding($involvedCounsellor, Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]));
    $other = arqSafeguarding();

    $this->actingAs($involved)->get(route('administrator.requests', ['type' => RequestTypeEnum::safeguardingException->value]))
        ->assertInertia(fn ($page) => $page
            ->has('requests.data', 2)
            ->where('requests.data', function ($data) use ($case, $other) {
                $mine = collect($data)->firstWhere('id', $case['request']->id);
                $theirs = collect($data)->firstWhere('id', $other['request']->id);

                return $mine['safeguardingException'] === ['involved' => true, 'isRespondent' => false]
                    && $mine['isRespondent'] === false
                    && is_null($mine['from']) && is_null($mine['for'])
                    && $theirs['safeguardingException']['involved'] === false
                    && $theirs['isRespondent'] === true;
            }));
});

test('answering from the queue goes through requests.respond as before', function () {
    $super = arqSuperAdmin();
    $rows = arqOneOfEach($super);
    $admin = arqAdmin();

    $this->actingAs($admin)->postJson(route('requests.respond', ['requestId' => $rows['organization']->id]), ['response' => 'accepted'])->assertSuccessful();

    expect($rows['organization']->refresh()->status)->toBe(RequestStatusEnum::accepted->value)
        ->and($rows['organization']->for->refresh()->verified_at)->not->toBeNull();
    expect(arqQueueIds($this->actingAs($admin)->get(route('administrator.requests'))))->not->toContain($rows['organization']->id);
});

// A paused minor in a group, and its counsellor's request for a safeguarding exception.
function arqGroupSafeguarding(): Request
{
    $minor = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $group = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id, 'status' => TherapyStatusEnum::in_session->value]);
    $group->users()->attach($minor->id, ['anonymous' => true, 'was_minor_at_join' => true]);

    return arqRequest(RequestTypeEnum::safeguardingException->value, $counsellor, null, $group, ['data' => ['reason' => 'x', 'proposedEndsAt' => now()->addDays(5)->toJSON(), 'wardId' => $minor->id]]);
}

test('the queue\'s query count does not grow with its rows', function () {
    $super = arqSuperAdmin();
    arqOneOfEach($super);
    arqGroupSafeguarding();

    $count = function () use ($super) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($super)->get(route('administrator.requests'))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $count();
    $few = $count();

    // Two more of each type (age verifications included: their identity documents are preloaded,
    // SCRUM-386), and of group safeguarding requests.
    foreach (range(1, 2) as $i) {
        arqOneOfEach($super);
        arqGroupSafeguarding();
    }

    expect($count())->toBeLessThanOrEqual($few + 1);
});
