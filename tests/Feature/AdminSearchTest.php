<?php

use App\Enums\AdministratorTypeEnum;
use App\Enums\RefundStatusEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Models\AdminContentAccess;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\CounsellorPayout;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\Organization;
use App\Models\Refund;
use App\Models\Report;
use App\Models\Request;
use App\Models\SafeguardingException;
use App\Models\Suspension;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use App\Support\AdminSearch;

// SCRUM-390: search on the admin list pages -- the fields each page shows, wildcards matched
// literally, never escaping the page's other filters, and never matching a row the viewer may only
// see as "involved". Helpers are file-local and asr-prefixed (--parallel).

function asrAdmin(string $type = 'NORMAL'): User
{
    return User::factory()->adult()->has(Administrator::factory()->state(['type' => $type === 'SUPER' ? AdministratorTypeEnum::super->value : AdministratorTypeEnum::normal->value]))->create();
}

function asrPerson(string $first, string $last = 'Person', array $attributes = []): User
{
    return User::factory()->adult()->create(['firstName' => $first, 'lastName' => $last, ...$attributes]);
}

function asrRequest(string $type, $from, $to, $for, array $data = [], string $status = 'PENDING'): Request
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

function asrReport(User $reporter, $reportable): Report
{
    $report = new Report(['description' => 'Something happened']);
    $report->addedby()->associate($reporter);
    $report->reportable()->associate($reportable);
    $report->save();

    return $report;
}

function asrPageIds($response, string $prop): array
{
    return collect($response->viewData('page')['props'][$prop]['data'])->pluck('id')->sort()->values()->all();
}

test('users are searched by names, username and email; wildcards are literal; short text searches nothing; long text is refused', function () {
    $admin = asrAdmin();
    $zelda = asrPerson('Zelda', 'Quinn', ['username' => 'zq_user', 'email' => 'zelda.q@example.test']);
    $percent = asrPerson('Percent', 'Sign', ['username' => '100%_sure']);
    asrPerson('Other', 'Someone', ['username' => 'other_someone', 'email' => 'other.someone@example.test']);

    $ids = fn (string $search) => collect($this->actingAs($admin)->getJson(route('admin.users', ['search' => $search]))->assertOk()->json('data'))->pluck('id')->all();

    expect($ids('Zelda'))->toBe([$zelda->id])
        ->and($ids('zq_us'))->toBe([$zelda->id])
        ->and($ids('zelda.q@'))->toBe([$zelda->id])
        ->and($ids('0%_'))->toBe([$percent->id])
        ->and(count($ids('z')))->toBeGreaterThan(1);

    $this->actingAs($admin)->getJson(route('admin.users', ['search' => str_repeat('a', 101)]))->assertStatus(422);
    $this->actingAs($admin)->getJson(route('admin.users', ['search' => ['x']]))->assertStatus(422);
});

test('the requests queue is searched by who sent it, without escaping its status filter', function () {
    $admin = asrAdmin();
    $sender = asrPerson('Searchable', 'Sender');
    $pending = asrRequest(RequestTypeEnum::ageVerification->value, $sender, null, $sender, ['attestation' => 'True.', 'attestedDob' => '1990-01-01']);
    $answered = asrRequest(RequestTypeEnum::dobChange->value, $sender, null, $sender, ['newDob' => '1990-01-01', 'priorDob' => '1991-01-01'], RequestStatusEnum::rejected->value);
    $other = asrPerson('Unrelated');
    asrRequest(RequestTypeEnum::ageVerification->value, $other, null, $other, ['attestation' => 'True.', 'attestedDob' => '1990-01-01']);

    $response = $this->actingAs($admin)->get(route('administrator.requests', ['search' => 'Searchable']));

    expect(asrPageIds($response, 'requests'))->toBe([$pending->id])
        ->and($response->viewData('page')['props']['search'])->toBe('Searchable');
    expect(asrPageIds($this->actingAs($admin)->get(route('administrator.requests', ['search' => 'Searchable', 'status' => 'all'])), 'requests'))
        ->toBe(collect([$pending->id, $answered->id])->sort()->values()->all());
});

test('a report the viewer is involved in never matches a search, though others do', function () {
    $admin = asrAdmin();
    $reporter = asrPerson('Reporterine', 'Smith');
    $mine = asrReport($admin, Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $reporter->id, 'name' => 'Reporterine therapy']));
    $notMine = asrReport($reporter, Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => asrPerson('Client')->id, 'name' => 'Plain']));

    expect(asrPageIds($this->actingAs($admin)->get(route('administrator.reports', ['search' => 'Reporterine'])), 'reports'))->toBe([$notMine->id]);
    // Without a search the involved row is still listed (as a summary).
    expect(asrPageIds($this->actingAs($admin)->get(route('administrator.reports')), 'reports'))->toContain($mine->id);
});

test('a suspension is searched by the person, and one the viewer is involved in never matches', function () {
    $admin = asrAdmin();
    $suspend = function (User $person) {
        $report = asrReport(asrPerson('Reporter'), GroupTherapy::factory()->create());

        return Suspension::query()->forceCreate(['user_id' => $person->id, 'report_id' => $report->id, 'scope' => 'account',
            'public_reason' => 'Conduct', 'starts_at' => now()->subMinute(), 'ends_at' => now()->addDay()]);
    };
    $match = $suspend(asrPerson('Suspendable', 'One'));
    $ward = asrPerson('Suspendable', 'Ward');
    Guardianship::query()->create(['guardian_id' => $admin->id, 'ward_id' => $ward->id]);
    $suspend($ward);

    expect(asrPageIds($this->actingAs($admin)->get(route('administrator.suspensions', ['search' => 'Suspendable'])), 'suspensions'))->toBe([$match->id]);
});

test('payouts are searched by reference or counsellor; organisations by name; the read log by administrator', function () {
    $admin = asrAdmin('SUPER');
    $counsellor = fn () => Counsellor::factory()->create(['user_id' => asrPerson('Payee')->id]);
    $payout = CounsellorPayout::factory()->create(['counsellor_id' => $counsellor()->id, 'reference' => 'payout_findme_123']);
    CounsellorPayout::factory()->create(['counsellor_id' => $counsellor()->id]);
    expect(collect($this->actingAs($admin)->getJson(route('admin.payouts', ['search' => 'findme']))->assertOk()->json('data'))->pluck('id')->all())->toBe([$payout->id]);

    $suspendedOrg = Organization::factory()->create(['name' => 'Findable Org', 'billing_suspended_at' => now()]);
    Organization::factory()->create(['name' => 'Findable Fine Org']);
    expect(asrPageIds($this->actingAs($admin)->get(route('administrator.organization_billing', ['search' => 'Findable'])), 'organizations'))->toBe([$suspendedOrg->id]);

    $reader = asrAdmin();
    $reader->forceFill(['firstName' => 'Readerly'])->save();
    $access = AdminContentAccess::query()->create(['admin_user_id' => $reader->id, 'resource' => AdminContentAccess::THERAPY, 'subject_type' => Therapy::class, 'subject_id' => 1]);
    AdminContentAccess::query()->create(['admin_user_id' => $admin->id, 'resource' => AdminContentAccess::THERAPY, 'subject_type' => Therapy::class, 'subject_id' => 1]);
    expect(collect($this->actingAs($admin)->get(route('administrator.content-accesses', ['search' => 'Readerly']))->viewData('page')['props']['accesses']['data'])->pluck('id')->all())->toBe([$access->id]);
});

test('counsellor verification requests and renewals are searched by the counsellor', function () {
    $admin = asrAdmin();
    $match = Counsellor::factory()->unverified()->create(['user_id' => asrPerson('Verifyable')->id, 'name' => 'Dr. Verifyable']);
    $other = Counsellor::factory()->unverified()->create(['user_id' => asrPerson('Plain')->id, 'name' => 'Dr. Plain']);
    $request = asrRequest(RequestTypeEnum::counsellor->value, $match, asrAdmin('SUPER'), $match);
    asrRequest(RequestTypeEnum::counsellor->value, $other, asrAdmin('SUPER'), $other);

    expect(collect($this->actingAs($admin)->getJson(route('admin.verification.requests', ['search' => 'Verifyable']))->json('data'))->pluck('id')->all())->toBe([$request->id]);

    $due = Counsellor::factory()->verificationEndingIn(10)->create(['user_id' => asrPerson('Renewable')->id, 'name' => 'Dr. Renewable']);
    Counsellor::factory()->verificationEndingIn(10)->create(['user_id' => asrPerson('Plain')->id]);
    expect(collect($this->actingAs($admin)->getJson(route('admin.counsellors.verification.renewals', ['search' => 'Renewable']))->json('data'))->pluck('id')->all())->toBe([$due->id]);
});

test('a safeguarding exception for a minor the super administrator counsels never matches their search', function () {
    $super = asrAdmin('SUPER');
    $counsellor = Counsellor::factory()->create(['user_id' => $super->id]);
    $counselled = asrPerson('Guarded', 'Minor', ['dob' => now()->subYears(15)->toDateString()]);
    Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $counselled->id, 'counsellor_id' => $counsellor->id, 'status' => TherapyStatusEnum::in_session->value]);
    $other = asrPerson('Guarded', 'Other', ['dob' => now()->subYears(15)->toDateString()]);

    $exception = fn (User $ward) => SafeguardingException::query()->forceCreate([
        'ward_id' => $ward->id, 'granted_by' => asrAdmin('SUPER')->id, 'reason' => 'Needed.', 'starts_at' => now(), 'ends_at' => now()->addDays(10),
    ]);
    $exception($counselled);
    $visible = $exception($other);

    expect(asrPageIds($this->actingAs($super)->get(route('administrator.safeguarding_exceptions', ['search' => 'Guarded'])), 'exceptions'))->toBe([$visible->id]);
});

test('a safeguarding request about a minor the super administrator counsels never matches their requests search', function () {
    $super = asrAdmin('SUPER');
    $theirs = Counsellor::factory()->create(['user_id' => $super->id]);
    $case = function (Counsellor $counsellor) {
        $minor = asrPerson('Minor', 'Ward', ['dob' => now()->subYears(15)->toDateString()]);
        $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $minor->id, 'counsellor_id' => $counsellor->id,
            'status' => TherapyStatusEnum::in_session->value, 'anonymous' => false, 'client_was_minor_at_creation' => true]);
        $sender = Counsellor::factory()->create(['user_id' => asrPerson('Senderly')->id, 'name' => 'Dr. Senderly']);

        return asrRequest(RequestTypeEnum::safeguardingException->value, $sender, null, $therapy,
            ['reason' => 'Needs to keep going.', 'proposedEndsAt' => now()->addDays(10)->toJSON(), 'wardId' => $minor->id]);
    };
    $case($theirs);
    $visible = $case(Counsellor::factory()->create(['user_id' => asrPerson('Other')->id]));

    expect(asrPageIds($this->actingAs($super)->get(route('administrator.requests', ['search' => 'Senderly'])), 'requests'))->toBe([$visible->id]);
});

function asrRows($response, string $prop): array
{
    $value = $response->viewData('page')['props'][$prop];

    return collect($value['data'] ?? $value)->pluck('id')->all();
}

test('refunds are searched by the payer or the payment reference when waiting, and by the refund\'s own reference when failed', function () {
    $admin = asrAdmin();
    $payer = asrPerson('Payerly', 'Client');
    $payment = fn (User $person, array $state = []) => Transaction::factory()->create(['user_id' => $person->id, 'for_type' => Therapy::class,
        'for_id' => Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $person->id])->id, 'status' => 'SUCCESS', ...$state]);
    $transaction = $payment($payer, ['reference' => 'pay_lookup_777']);
    $pending = asrRequest(RequestTypeEnum::refund->value, $payer, null, $transaction, ['reason' => 'Not held.']);
    $plain = asrPerson('Plain');
    asrRequest(RequestTypeEnum::refund->value, $plain, null, $payment($plain), ['reason' => 'Not held.']);
    $failed = Refund::factory()->create(['transaction_id' => $transaction->id, 'requested_by_id' => $payer->id,
        'status' => RefundStatusEnum::failed->value, 'reference' => 'refund_seen_888']);
    Refund::factory()->create(['transaction_id' => $payment($plain)->id, 'requested_by_id' => $plain->id,
        'status' => RefundStatusEnum::failed->value]);

    $page = fn (string $search) => $this->actingAs($admin)->get(route('administrator.refund_requests', ['search' => $search]));

    expect(asrRows($page('Payerly'), 'refundRequests'))->toBe([$pending->id])
        ->and(asrRows($page('lookup_777'), 'refundRequests'))->toBe([$pending->id])
        ->and(asrRows($page('refund_seen'), 'failedRefunds'))->toBe([$failed->id])
        ->and(asrRows($page('Payerly'), 'failedRefunds'))->toBe([$failed->id]);
});

test('the involvement check reads at most the cap, newest ids first, and says when there were more', function () {
    $people = User::factory()->adult()->count(AdminSearch::CAP + 2)->create(['firstName' => 'Capped']);
    $viewer = asrAdmin();
    $hidden = $people->sortBy('id')->last();

    $query = User::query()->where('firstName', 'Capped');
    $tooMany = AdminSearch::withoutInvolved($query, $viewer, fn (User $row) => $row->is($hidden));
    $ids = $query->pluck('id');

    expect($tooMany)->toBeTrue()
        ->and($ids)->toHaveCount(AdminSearch::CAP - 1)
        ->and($ids)->not->toContain($hidden->id);
});
