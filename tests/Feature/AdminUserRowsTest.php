<?php

use App\Enums\AdministratorTypeEnum;
use App\Enums\SuspensionScopeEnum;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\Report;
use App\Models\Suspension;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// SCRUM-430: the admin users list is a table -- each row says the person's role and whether their account is
// suspended, worked out in the list's own query (never a query per row).

function aurAdmin(string $type = 'NORMAL'): User
{
    return User::factory()->adult()->has(Administrator::factory()->state(['type' => $type]))->create();
}

function aurSuspend(User $person, SuspensionScopeEnum $scope, ?User $reporter = null): void
{
    $report = new Report(['description' => 'Conduct']);
    $report->addedby()->associate($reporter ?? User::factory()->adult()->create());
    $report->reportable()->associate(GroupTherapy::factory()->create());
    $report->save();

    Suspension::query()->forceCreate(['user_id' => $person->id, 'report_id' => $report->id, 'scope' => $scope->value, 'public_reason' => 'Conduct',
        'starts_at' => now()->subMinute(), 'ends_at' => now()->addDay()]);
}

function aurCounsellor(): User
{
    $user = User::factory()->adult()->create();
    Counsellor::factory()->create(['user_id' => $user->id]);

    return $user;
}

function aurRows($test, User $viewer): Collection
{
    return collect($test->actingAs($viewer)->getJson(route('admin.users'))->assertOk()->json('data'))->keyBy('id');
}

test('each row says the person\'s role', function () {
    $viewer = aurAdmin();
    $super = aurAdmin(AdministratorTypeEnum::super->value);
    $admin = aurAdmin();
    $counsellor = aurCounsellor();
    $user = User::factory()->adult()->create();

    $rows = aurRows($this, $viewer);

    expect($rows[$super->id]['role'])->toBe('super admin')
        ->and($rows[$admin->id]['role'])->toBe('admin')
        ->and($rows[$counsellor->id]['role'])->toBe('counsellor')
        ->and($rows[$user->id]['role'])->toBe('user');
});

test('a suspended account shows as suspended; a counsellor-only suspension doesn\'t', function () {
    $viewer = aurAdmin();
    $suspended = User::factory()->adult()->create();
    aurSuspend($suspended, SuspensionScopeEnum::account);
    $counsellorOnly = aurCounsellor();
    aurSuspend($counsellorOnly, SuspensionScopeEnum::counsellor);
    $fine = User::factory()->adult()->create();

    $rows = aurRows($this, $viewer);

    expect($rows[$suspended->id]['accountSuspended'])->toBeTrue()
        ->and($rows[$counsellorOnly->id]['accountSuspended'])->toBeFalse()
        ->and($rows[$fine->id]['accountSuspended'])->toBeFalse();
});

test('the list\'s queries don\'t grow with its rows', function () {
    $viewer = aurAdmin();
    // All on the one page counted (a search only they match), a suspended one among them (code review). Each suspended
    // row's involvement check (Suspension::involves) runs its own few queries, as on the suspensions list -- at most a
    // page of them; every other row adds none.
    $person = fn () => User::factory()->adult()->create(['firstName' => 'Qcountable']);
    aurSuspend($person(), SuspensionScopeEnum::account);
    $person();

    $count = function () use ($viewer) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        expect($this->actingAs($viewer)->getJson(route('admin.users', ['search' => 'Qcountable']))->assertOk()->json('data'))->not->toBeEmpty();

        return count(DB::getQueryLog());
    };

    $count(); // the first request's one-off queries (the session, the visitor record)
    $few = $count();
    foreach (range(1, 3) as $n) {
        $counsellor = $person();
        Counsellor::factory()->create(['user_id' => $counsellor->id]);
        $person();
    }

    expect($count())->toBe($few);
});

// Security review: the suspensions list keeps who was suspended from an administrator involved in it -- so does this.
test('an administrator involved in a suspension isn\'t told the account is suspended', function () {
    $guardian = aurAdmin();
    $ward = User::factory()->adult()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $ward->id]);
    aurSuspend($ward, SuspensionScopeEnum::account);

    $reporter = aurAdmin();
    $reported = User::factory()->adult()->create();
    aurSuspend($reported, SuspensionScopeEnum::account, $reporter);

    expect(aurRows($this, $guardian)[$ward->id]['accountSuspended'])->toBeNull()
        ->and(aurRows($this, $reporter)[$reported->id]['accountSuspended'])->toBeNull()
        ->and(aurRows($this, aurAdmin())[$ward->id]['accountSuspended'])->toBeTrue();
});
