<?php

use App\Actions\Admin\GetAdminToDoCountsAction;
use App\Enums\AdministratorTypeEnum;
use App\Enums\CounsellorEarningStatusEnum;
use App\Enums\CounsellorPayoutStatusEnum;
use App\Enums\OrganizationInvoiceStatusEnum;
use App\Enums\RefundStatusEnum;
use App\Enums\ReportStatusEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\CounsellorEarning;
use App\Models\CounsellorPayout;
use App\Models\LicensingAuthority;
use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Models\Refund;
use App\Models\Report;
use App\Models\Request;
use App\Models\Suspension;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// SCRUM-387 (+ SCRUM-49): GetAdminToDoCountsAction -- the overview's to-do counts and the sidebar
// badges: they match the lists they link to, each is only for who may see it, and an answered
// request drops out straight away. Helpers are file-local and atd-prefixed (--parallel).

beforeEach(fn () => Cache::flush());

function atdAdmin(): User
{
    return User::factory()->adult()->has(Administrator::factory()->state(['type' => AdministratorTypeEnum::normal->value]))->create();
}

function atdSuperAdmin(): User
{
    return User::factory()->adult()->has(Administrator::factory()->state(['type' => AdministratorTypeEnum::super->value]))->create();
}

function atdRequest(string $type, $from, $to, $for, array $data = [], string $status = 'PENDING'): Request
{
    $request = new Request(['type' => $type, 'data' => $data, 'status' => $status]);
    $request->from()->associate($from);
    if ($to) {
        $request->to()->associate($to);
    }
    $request->for()->associate($for);
    $request->save();

    return $request;
}

function atdCounsellor(?User $user = null): Counsellor
{
    return Counsellor::factory()->create(['user_id' => ($user ?? User::factory()->adult()->create())->id]);
}

function atdTherapy(User $client, ?Counsellor $counsellor = null): Therapy
{
    return Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor?->id,
        'status' => TherapyStatusEnum::in_session->value, 'anonymous' => false,
    ]);
}

function atdReport(string $status): Report
{
    $client = User::factory()->adult()->create();
    $report = new Report(['description' => 'Conduct']);
    $report->addedby()->associate($client);
    $report->reportable()->associate(atdTherapy($client));
    $report->save();
    $report->forceFill(['status' => $status])->save();

    return $report;
}

function atdSafeguarding(Counsellor $counsellor, ?Counsellor $sender = null): Request
{
    $minor = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);
    $therapy = atdTherapy($minor, $counsellor);

    return atdRequest(RequestTypeEnum::safeguardingException->value, $sender ?? $counsellor, null, $therapy, [
        'reason' => 'Keep going.', 'proposedEndsAt' => now()->addDays(10)->toJSON(), 'wardId' => $minor->id,
    ]);
}

// Something waiting for every count.
function atdEverything(User $firstSuper): array
{
    $person = User::factory()->adult()->create();
    $counsellor = atdCounsellor();
    $organization = Organization::factory()->create();
    $transaction = Transaction::factory()->create(['user_id' => $person->id, 'for_type' => Therapy::class, 'for_id' => atdTherapy($person)->id, 'status' => 'SUCCESS']);

    $rows = [
        'counsellor' => atdRequest(RequestTypeEnum::counsellor->value, $counsellor, $firstSuper, $counsellor),
        'organization' => atdRequest(RequestTypeEnum::organization->value, $organization, $firstSuper, $organization),
        'ageVerification' => atdRequest(RequestTypeEnum::ageVerification->value, $person, null, $person, ['attestation' => 'True.', 'attestedDob' => '1990-01-01']),
        'dobChange' => atdRequest(RequestTypeEnum::dobChange->value, $person, null, $person, ['newDob' => '2010-01-01', 'priorDob' => '1990-01-01']),
        'refund' => atdRequest(RequestTypeEnum::refund->value, $person, null, $transaction, ['reason' => 'No show.']),
        'safeguarding' => atdSafeguarding(atdCounsellor()),
    ];

    // Not waiting: answered, or a dob change a guardian answers.
    atdRequest(RequestTypeEnum::organization->value, $organization, $firstSuper, $organization, [], RequestStatusEnum::rejected->value);
    atdRequest(RequestTypeEnum::dobChange->value, $person, User::factory()->adult()->create(), $person, ['newDob' => '2010-01-01', 'priorDob' => '1990-01-01']);

    atdReport(ReportStatusEnum::open->value);
    atdReport(ReportStatusEnum::under_review->value);
    $resolved = atdReport(ReportStatusEnum::resolved->value);

    $suspended = User::factory()->adult()->create();
    Suspension::query()->create(['user_id' => $suspended->id, 'report_id' => $resolved->id, 'scope' => 'account', 'starts_at' => now()->subDay(), 'public_reason' => 'Conduct', 'created_by' => $firstSuper->id, 'appeal_text' => 'Please.', 'appealed_at' => now()]);
    Suspension::query()->create(['user_id' => $suspended->id, 'report_id' => $resolved->id, 'scope' => 'account', 'starts_at' => now()->subDays(9), 'public_reason' => 'Conduct', 'created_by' => $firstSuper->id, 'appeal_text' => 'Please.', 'appealed_at' => now()->subDays(8), 'appeal_decided_at' => now()->subDays(7), 'appeal_decision' => 'upheld']);

    $payee = atdCounsellor();
    $failed = CounsellorPayout::factory()->create(['counsellor_id' => $payee->id, 'status' => CounsellorPayoutStatusEnum::failed->value]);
    CounsellorEarning::factory()->create(['counsellor_id' => $payee->id, 'transaction_id' => $transaction->id, 'counsellor_payout_id' => $failed->id, 'status' => CounsellorEarningStatusEnum::pending->value]);
    // Failed, but its earnings were paid out again since -- not waiting: none left, or none pending.
    CounsellorPayout::factory()->create(['counsellor_id' => $payee->id, 'status' => CounsellorPayoutStatusEnum::failed->value]);
    $repaid = CounsellorPayout::factory()->create(['counsellor_id' => $payee->id, 'status' => CounsellorPayoutStatusEnum::failed->value]);
    CounsellorEarning::factory()->create(['counsellor_id' => $payee->id, 'transaction_id' => $transaction->id, 'counsellor_payout_id' => $repaid->id, 'status' => CounsellorEarningStatusEnum::paidOut->value]);

    Refund::factory()->create(['transaction_id' => $transaction->id, 'requested_by_id' => $person->id, 'status' => RefundStatusEnum::failed->value]);
    Refund::factory()->create(['transaction_id' => $transaction->id, 'requested_by_id' => $person->id, 'status' => RefundStatusEnum::success->value]);

    Organization::factory()->create(['billing_suspended_at' => now()]);
    OrganizationInvoice::factory()->create(['status' => OrganizationInvoiceStatusEnum::failed->value]);
    OrganizationInvoice::factory()->create(['status' => OrganizationInvoiceStatusEnum::settled->value]);

    DB::table('failed_jobs')->insert(['uuid' => (string) str()->uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'boom', 'failed_at' => now()]);

    return $rows;
}

test('each count is what is waiting, and the safeguarding and system counts are for super admins only', function () {
    $super = atdSuperAdmin();
    atdEverything($super);

    $expected = [
        'counsellorVerifications' => 1, 'organizationVerifications' => 1, 'ageVerifications' => 1, 'dobChanges' => 1,
        'refundRequests' => 1, 'reports' => 2, 'suspensionAppeals' => 1, 'failedPayouts' => 1, 'failedRefunds' => 1,
        'organizationBilling' => 2,
    ];

    $admin = GetAdminToDoCountsAction::new()->counts(atdAdmin(), overview: true);
    expect(collect($admin)->only(array_keys($expected))->all())->toEqual($expected)
        ->and($admin)->toHaveKey('pausedMinors')
        ->not->toHaveKeys(['safeguardingRequests', 'safeguardingAnswerable', 'safeguardingInvolved', 'safeguardingNoLongerNeeded', 'failedJobs']);

    $superCounts = GetAdminToDoCountsAction::new()->counts($super);
    expect(collect($superCounts)->only(array_keys($expected))->all())->toEqual($expected)
        ->and($superCounts['safeguardingRequests'])->toBe(1)
        ->and($superCounts['safeguardingAnswerable'])->toBe(1)
        ->and($superCounts['safeguardingInvolved'])->toBe(0)
        ->and($superCounts['failedJobs'])->toBe(1)
        ->and($superCounts)->not->toHaveKey('pausedMinors');

    expect(GetAdminToDoCountsAction::new()->counts(User::factory()->create()))->toBe([])
        ->and(GetAdminToDoCountsAction::new()->execute(User::factory()->create()))->toBe([])
        ->and(GetAdminToDoCountsAction::new()->badges(User::factory()->create()))->toBe([]);
});

test('the counts match the lists they link to', function () {
    $super = atdSuperAdmin();
    atdEverything($super);
    $admin = atdAdmin();
    $counts = GetAdminToDoCountsAction::new()->counts($admin);

    $this->actingAs($admin)->get(route('administrator.refund_requests'))
        ->assertInertia(fn ($page) => $page
            ->where('refundRequests.meta.total', $counts['refundRequests'])
            // Failed refunds are listed on the same page (SCRUM-387 review).
            ->where('failedRefundsTotal', $counts['failedRefunds'])
            ->has('failedRefunds', $counts['failedRefunds']));
    $this->actingAs($admin)->get(route('administrator.reports'))
        ->assertInertia(fn ($page) => $page->where('reports.meta.total', $counts['reports']));
    // Billing-suspended organisations and ones with a failed invoice, both listed.
    $this->actingAs($admin)->get(route('administrator.organization_billing'))
        ->assertInertia(fn ($page) => $page->where('organizations.meta.total', $counts['organizationBilling']));

    foreach ([
        'counsellorVerifications' => RequestTypeEnum::counsellor->value,
        'organizationVerifications' => RequestTypeEnum::organization->value,
        'ageVerifications' => RequestTypeEnum::ageVerification->value,
        'dobChanges' => RequestTypeEnum::dobChange->value,
        'refundRequests' => RequestTypeEnum::refund->value,
    ] as $key => $type) {
        $this->actingAs($admin)->get(route('administrator.requests', ['type' => $type]))
            ->assertInertia(fn ($page) => $page->where('requests.meta.total', $counts[$key]));
    }

    $this->actingAs($admin)->getJson(route('admin.verification.requests', ['filterType' => 'status', 'filterValue' => 'PENDING']))
        ->assertJsonPath('meta.total', $counts['counsellorVerifications']);

    // The badge for the queue is everything pending in it.
    $badges = GetAdminToDoCountsAction::new()->badges($super);
    $this->actingAs($super)->get(route('administrator.requests'))
        ->assertInertia(fn ($page) => $page->where('requests.meta.total', $badges['requests']));
});

test('an admin\'s own age verification is left out of their count, not anyone else\'s', function () {
    $admin = atdAdmin();
    $other = atdAdmin();
    atdRequest(RequestTypeEnum::ageVerification->value, $admin, null, $admin, ['attestation' => 'Mine.', 'attestedDob' => '1990-01-01']);

    // The other admin's count is worked out first, so it is the one cached.
    expect(GetAdminToDoCountsAction::new()->counts($other)['ageVerifications'])->toBe(1)
        ->and(GetAdminToDoCountsAction::new()->counts($admin)['ageVerifications'])->toBe(0)
        ->and(GetAdminToDoCountsAction::new()->counts($other)['ageVerifications'])->toBe(1);
});

test('a super admin\'s safeguarding counts split into what they can answer and what they are involved in', function () {
    $super = atdSuperAdmin();
    $theirCounsellor = atdCounsellor($super);
    atdSafeguarding($theirCounsellor, atdCounsellor());
    atdSafeguarding(atdCounsellor());
    atdSafeguarding(atdCounsellor());

    $counts = GetAdminToDoCountsAction::new()->counts($super);
    expect($counts['safeguardingRequests'])->toBe(3)
        ->and($counts['safeguardingAnswerable'])->toBe(2)
        ->and($counts['safeguardingInvolved'])->toBe(1);

    // Person-scoped: another super admin answers all three.
    expect(GetAdminToDoCountsAction::new()->counts(atdSuperAdmin())['safeguardingAnswerable'])->toBe(3);
});

test('a safeguarding request for a minor who is no longer paused counts as no longer needed', function () {
    $super = atdSuperAdmin();
    $request = atdSafeguarding(atdCounsellor());
    atdSafeguarding(atdCounsellor());

    User::query()->whereKey($request->data['wardId'])->update(['dob' => now()->subYears(19)->toDateString()]);

    expect(GetAdminToDoCountsAction::new()->counts($super)['safeguardingNoLongerNeeded'])->toBe(1);
});

test('the counts are cached for a minute, and answering a request clears them', function () {
    $super = atdSuperAdmin();
    $rows = atdEverything($super);
    $admin = atdAdmin();

    expect(GetAdminToDoCountsAction::new()->counts($admin)['reports'])->toBe(2);
    atdReport(ReportStatusEnum::open->value);
    expect(GetAdminToDoCountsAction::new()->counts($admin)['reports'])->toBe(2);

    $this->travel(61)->seconds();
    expect(GetAdminToDoCountsAction::new()->counts($admin)['reports'])->toBe(3);

    expect(GetAdminToDoCountsAction::new()->counts($admin)['organizationVerifications'])->toBe(1);
    $this->actingAs($admin)->postJson(route('requests.respond', ['requestId' => $rows['organization']->id]), ['response' => 'rejected'])->assertSuccessful();
    expect(GetAdminToDoCountsAction::new()->counts($admin)['organizationVerifications'])->toBe(0);

    // The super admin's own cached split goes too.
    expect(GetAdminToDoCountsAction::new()->counts($super)['safeguardingAnswerable'])->toBe(1);
    $this->actingAs($super)->postJson(route('requests.respond', ['requestId' => $rows['safeguarding']->id]), ['response' => 'rejected'])->assertSuccessful();
    expect(GetAdminToDoCountsAction::new()->counts($super)['safeguardingAnswerable'])->toBe(0);
});

test('the overview lists the to-do items with links, and the badges are the cheap counts only', function () {
    $super = atdSuperAdmin();
    atdEverything($super);
    $admin = atdAdmin();

    $this->actingAs($admin)->get(route('administrator'))->assertInertia(fn ($page) => $page
        ->component('Admin/Overview')
        ->where('toDo', function ($items) {
            $items = collect($items)->keyBy('key');

            return $items['organizationVerifications']['count'] === 1
                && $items['organizationVerifications']['route'] === 'administrator.requests'
                && $items['organizationVerifications']['params'] === ['type' => RequestTypeEnum::organization->value]
                && $items['reports']['count'] === 2
                && $items->has('pausedMinors')
                && ! $items->has('failedJobs')
                && ! $items->has('safeguardingAnswerable');
        }));

    $this->actingAs($super)->get(route('administrator'))->assertInertia(fn ($page) => $page
        ->where('toDo', fn ($items) => collect($items)->keyBy('key')->has(['failedJobs', 'safeguardingAnswerable', 'safeguardingInvolved', 'safeguardingNoLongerNeeded'])));

    expect(array_keys(GetAdminToDoCountsAction::new()->badges($admin)))->not->toContain('pausedMinors')
        ->and(GetAdminToDoCountsAction::new()->badges($admin)['requests'])->toBe(5)
        ->and(GetAdminToDoCountsAction::new()->badges($super)['requests'])->toBe(6);
});

test('the admin-area seed gives the verification, failed payout and failed refund counts something to show', function () {
    $super = atdSuperAdmin();
    LicensingAuthority::factory()->count(2)->create(['name' => 'Demo authority', 'type' => 'GOVERMENTAL', 'license_type' => 'NUMBER']);
    User::factory()->create(['username' => 'payout_demo_client']);

    (new DatabaseSeeder)->createAdminAreaDemoData($super);

    $counts = GetAdminToDoCountsAction::new()->counts(atdAdmin());
    expect($counts['counsellorVerifications'])->toBe(1)
        ->and($counts['organizationVerifications'])->toBe(1)
        ->and($counts['failedPayouts'])->toBe(1)
        ->and($counts['failedRefunds'])->toBe(1);
});

test('the shared counts are the same whoever fills the cache: a super admin first, then an ordinary admin', function () {
    $super = atdSuperAdmin();
    atdEverything($super);
    $admin = atdAdmin();

    $superCounts = GetAdminToDoCountsAction::new()->counts($super);
    $adminCounts = GetAdminToDoCountsAction::new()->counts($admin);

    expect($adminCounts)->not->toHaveKeys(['safeguardingRequests', 'safeguardingAnswerable', 'failedJobs'])
        ->and(collect($superCounts)->only(array_keys($adminCounts))->all())->toEqual($adminCounts)
        ->and(GetAdminToDoCountsAction::new()->badges($admin)['requests'])->toBe(5)
        ->and(GetAdminToDoCountsAction::new()->badges($super)['requests'])->toBe(6);
});

test('a new admin queue request clears the counts at once -- an admin\'s own age verification is never undercounted', function () {
    $admin = atdAdmin();
    $other = atdAdmin();
    expect(GetAdminToDoCountsAction::new()->counts($other)['ageVerifications'])->toBe(0);

    atdRequest(RequestTypeEnum::ageVerification->value, $admin, null, $admin, ['attestation' => 'Mine.', 'attestedDob' => '1990-01-01']);

    expect(GetAdminToDoCountsAction::new()->counts($admin)['ageVerifications'])->toBe(0)
        ->and(GetAdminToDoCountsAction::new()->counts($other)['ageVerifications'])->toBe(1);
});

test('answering a request that isn\'t admin work leaves the cached counts alone', function () {
    $admin = atdAdmin();
    $guardian = User::factory()->adult()->create();
    $ward = User::factory()->create();
    $request = atdRequest(RequestTypeEnum::guardianship->value, $ward, $guardian, $ward);

    GetAdminToDoCountsAction::new()->counts($admin);
    $generation = Cache::get('admin-todo:generation');

    $this->actingAs($guardian)->postJson(route('requests.respond', ['requestId' => $request->id]), ['response' => 'rejected'])->assertSuccessful();

    expect($request->refresh()->status)->toBe(RequestStatusEnum::rejected->value)
        ->and(Cache::get('admin-todo:generation'))->toBe($generation);
});

test('a cache failure while clearing the counts doesn\'t fail the answer', function () {
    $super = atdSuperAdmin();
    $rows = atdEverything($super);
    $admin = atdAdmin();

    Cache::partialMock()->shouldReceive('forever')->andThrow(new RuntimeException('cache down'));

    $this->actingAs($admin)->postJson(route('requests.respond', ['requestId' => $rows['organization']->id]), ['response' => 'rejected'])->assertSuccessful();

    expect($rows['organization']->refresh()->status)->toBe(RequestStatusEnum::rejected->value);
});

test('the paused-minors count is kept apart from the generation an answer moves on', function () {
    $super = atdSuperAdmin();
    $rows = atdEverything($super);
    $admin = atdAdmin();

    GetAdminToDoCountsAction::new()->counts($admin, overview: true);
    expect(Cache::has('admin-todo:paused-minors'))->toBeTrue();

    $this->actingAs($admin)->postJson(route('requests.respond', ['requestId' => $rows['organization']->id]), ['response' => 'rejected'])->assertSuccessful();

    expect(Cache::has('admin-todo:paused-minors'))->toBeTrue();
});
