<?php

use App\Actions\Admin\GetPlatformStatsAction;
use App\Enums\AdministratorTypeEnum;
use App\Enums\CounsellorEarningStatusEnum;
use App\Enums\CounsellorPayoutStatusEnum;
use App\Enums\OrganizationInvoiceStatusEnum;
use App\Enums\RefundStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\SessionStatusEnum;
use App\Enums\TherapyStatusEnum;
use App\Enums\TransactionStatusEnum;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\CounsellorEarning;
use App\Models\CounsellorPayout;
use App\Models\GroupTherapy;
use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Models\Refund;
use App\Models\Request;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VideoSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

// SCRUM-387 (+ SCRUM-49): GetPlatformStatsAction -- totals now, and per period with the previous
// one alongside; money per currency from successful, non-reversed rows only. Helpers are
// file-local and aps-prefixed (--parallel).

beforeEach(function () {
    Cache::flush();
    // A Wednesday, so "this week" (from Monday) and "last week" are both well inside one month.
    $this->travelTo(Carbon::parse('2026-11-18 12:00:00'));
});

function apsAdmin(): User
{
    return User::factory()->adult()->has(Administrator::factory()->state(['type' => AdministratorTypeEnum::normal->value]))->create();
}

function apsCounsellor(array $attributes = []): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id, ...$attributes]);
}

function apsTherapy(string $status = 'IN_SESSION'): Therapy
{
    $client = User::factory()->adult()->create();

    return Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'status' => $status]);
}

function apsTransaction(string $status, int $amount, string $currency, string $at): Transaction
{
    $therapy = apsTherapy();

    return Transaction::factory()->create([
        'user_id' => $therapy->addedby_id, 'for_type' => Therapy::class, 'for_id' => $therapy->id,
        'status' => $status, 'amount' => $amount, 'currency' => $currency, 'created_at' => $at,
    ]);
}

function apsStats(): array
{
    return GetPlatformStatsAction::new()->execute(apsAdmin());
}

test('money is per currency and counts only successful, non-reversed rows', function () {
    $thisWeek = '2026-11-17 10:00:00';

    $ghs = apsTransaction(TransactionStatusEnum::success->value, 10000, 'GHS', $thisWeek);
    apsTransaction(TransactionStatusEnum::success->value, 2500, 'GHS', $thisWeek);
    apsTransaction(TransactionStatusEnum::success->value, 700, 'USD', $thisWeek);
    apsTransaction(TransactionStatusEnum::failed->value, 99999, 'GHS', $thisWeek);
    apsTransaction(TransactionStatusEnum::abandoned->value, 99999, 'USD', $thisWeek);
    apsTransaction(TransactionStatusEnum::pending->value, 99999, 'GHS', $thisWeek);

    $counsellor = apsCounsellor();
    $earning = fn (string $status, int $fee, string $currency) => CounsellorEarning::factory()->create([
        'transaction_id' => $ghs->id, 'counsellor_id' => $counsellor->id, 'gross_amount' => $fee * 10, 'fee_amount' => $fee,
        'net_amount' => $fee * 9, 'currency' => $currency, 'status' => $status, 'created_at' => $thisWeek,
    ]);
    $earning(CounsellorEarningStatusEnum::pending->value, 1000, 'GHS');
    $earning(CounsellorEarningStatusEnum::paidOut->value, 250, 'GHS');
    $earning(CounsellorEarningStatusEnum::reversed->value, 5000, 'GHS');
    $earning(CounsellorEarningStatusEnum::pending->value, 70, 'USD');

    $payout = fn (string $status, int $amount) => CounsellorPayout::factory()->create(['counsellor_id' => $counsellor->id, 'status' => $status, 'amount' => $amount, 'currency' => 'GHS', 'created_at' => $thisWeek]);
    $payout(CounsellorPayoutStatusEnum::succeeded->value, 4000);
    $payout(CounsellorPayoutStatusEnum::failed->value, 9999);
    $payout(CounsellorPayoutStatusEnum::processing->value, 9999);

    $refund = fn (string $status, int $amount) => Refund::factory()->create(['transaction_id' => $ghs->id, 'requested_by_id' => $ghs->user_id, 'status' => $status, 'amount' => $amount, 'currency' => 'GHS', 'created_at' => $thisWeek]);
    $refund(RefundStatusEnum::success->value, 1500);
    $refund(RefundStatusEnum::failed->value, 9999);

    $invoice = fn (string $status, int $amount, string $currency) => OrganizationInvoice::factory()->create(['status' => $status, 'amount' => $amount, 'currency' => $currency, 'created_at' => $thisWeek]);
    $invoice(OrganizationInvoiceStatusEnum::settled->value, 3000, 'USD');
    $invoice(OrganizationInvoiceStatusEnum::failed->value, 9999, 'USD');

    $money = apsStats()['periods']['week']['current']['money'];

    expect($money['revenue'])->toBe(['GHS' => 12500, 'USD' => 700])
        ->and($money['platformFees'])->toBe(['GHS' => 1250, 'USD' => 70])
        ->and($money['payoutsPaid'])->toBe(['GHS' => 4000])
        ->and($money['refundsPaid'])->toBe(['GHS' => 1500])
        ->and($money['invoicesSettled'])->toBe(['USD' => 3000]);
});

test('each period counts its own rows, with the previous week or month alongside, and all time everything', function () {
    // This week (from Monday 16 Nov), last week, earlier this month, last month.
    $at = ['week' => '2026-11-17 09:00:00', 'lastWeek' => '2026-11-10 09:00:00', 'month' => '2026-11-02 09:00:00', 'lastMonth' => '2026-10-15 09:00:00'];

    apsTransaction(TransactionStatusEnum::success->value, 100, 'GHS', $at['week']);
    apsTransaction(TransactionStatusEnum::success->value, 200, 'GHS', $at['lastWeek']);
    apsTransaction(TransactionStatusEnum::success->value, 400, 'GHS', $at['month']);
    apsTransaction(TransactionStatusEnum::success->value, 800, 'GHS', $at['lastMonth']);

    $therapy = apsTherapy();
    $session = fn (string $status, string $start) => Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id, 'status' => $status, 'start_time' => $start, 'end_time' => $start]);
    $session(SessionStatusEnum::held->value, $at['week']);
    $session(SessionStatusEnum::held->value, $at['week']);
    $session(SessionStatusEnum::held->value, $at['week']);
    $session(SessionStatusEnum::failed->value, $at['week']);
    $session(SessionStatusEnum::held->value, $at['lastWeek']);

    $video = Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id, 'status' => SessionStatusEnum::held->value, 'start_time' => $at['lastMonth'], 'end_time' => $at['lastMonth']]);
    VideoSession::factory()->create(['session_id' => $video->id, 'started_at' => '2026-11-17 10:00:00', 'ended_at' => '2026-11-17 10:45:00']);
    VideoSession::factory()->create(['session_id' => $video->id, 'started_at' => '2026-11-10 10:00:00', 'ended_at' => '2026-11-10 10:30:00']);
    // Still going: not counted.
    VideoSession::factory()->create(['session_id' => $video->id, 'started_at' => '2026-11-18 11:00:00', 'ended_at' => null]);

    $stats = apsStats();
    $week = $stats['periods']['week'];
    $month = $stats['periods']['month'];
    $all = $stats['periods']['all'];

    expect($week['current']['money']['revenue'])->toBe(['GHS' => 100])
        ->and($week['previous']['money']['revenue'])->toBe(['GHS' => 200])
        ->and($month['current']['money']['revenue'])->toBe(['GHS' => 700])
        ->and($month['previous']['money']['revenue'])->toBe(['GHS' => 800])
        ->and($all['current']['money']['revenue'])->toBe(['GHS' => 1500])
        ->and($all['previous'])->toBeNull();

    expect($week['current']['sessionsHeld'])->toBe(3)
        ->and($week['current']['sessionsFailedOrAbandoned'])->toBe(1)
        ->and($week['current']['failedOrAbandonedShare'])->toBe(0.25)
        ->and($week['previous']['sessionsHeld'])->toBe(1)
        ->and($week['previous']['failedOrAbandonedShare'])->toEqual(0);

    expect($week['current']['videoMinutesApprox'])->toBe(45)
        ->and($week['previous']['videoMinutesApprox'])->toBe(30)
        ->and($all['current']['videoMinutesApprox'])->toBe(75);
});

test('new users per period leave out closed and anonymised accounts', function () {
    User::factory()->count(2)->create(['created_at' => '2026-11-17 09:00:00']);
    User::factory()->create(['created_at' => '2026-11-10 09:00:00']);
    User::factory()->create(['created_at' => '2026-11-17 09:00:00', 'deleted_at' => now()]);
    User::factory()->create(['created_at' => '2026-11-17 09:00:00', 'anonymised_at' => now()]);

    $periods = apsStats()['periods'];

    // The admin apsStats() signs in as is new this week too.
    expect($periods['week']['current']['newUsers'])->toBe(3)
        ->and($periods['week']['previous']['newUsers'])->toBe(1)
        ->and($periods['month']['current']['newUsers'])->toBe(4)
        ->and($periods['all']['current']['newUsers'])->toBe(4);
});

test('the totals: users, counsellors, organisations, therapies, upcoming sessions', function () {
    apsCounsellor(['verified_at' => now()]);
    $awaiting = apsCounsellor(['verified_at' => null]);
    apsCounsellor(['verified_at' => null]);
    $request = new Request(['type' => RequestTypeEnum::counsellor->value, 'data' => [], 'status' => 'PENDING']);
    $request->from()->associate($awaiting);
    $request->for()->associate($awaiting);
    $request->save();

    Organization::factory()->create(['verified_at' => now()]);
    Organization::factory()->create();

    apsTherapy(TherapyStatusEnum::in_session->value);
    $pending = apsTherapy(TherapyStatusEnum::pending->value);
    apsTherapy(TherapyStatusEnum::ended->value);
    GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $pending->addedby_id, 'status' => TherapyStatusEnum::in_session->value]);

    Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $pending->id, 'status' => SessionStatusEnum::pending->value, 'start_time' => now()->addDay(), 'end_time' => now()->addDay()]);
    Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $pending->id, 'status' => SessionStatusEnum::pending->value, 'start_time' => now()->subDay(), 'end_time' => now()->subDay()]);

    $totals = apsStats()['totals'];

    expect($totals['counsellors'])->toBe(3)
        ->and($totals['counsellorsVerified'])->toBe(1)
        ->and($totals['counsellorsAwaiting'])->toBe(1)
        ->and($totals['organizations'])->toBe(2)
        ->and($totals['organizationsVerified'])->toBe(1)
        ->and($totals['activeTherapies'])->toBe(1)
        ->and($totals['pendingTherapies'])->toBe(1)
        ->and($totals['activeGroupTherapies'])->toBe(1)
        ->and($totals['pendingGroupTherapies'])->toBe(0)
        ->and($totals['upcomingSessions'])->toBe(1)
        ->and($totals['users'])->toBe(User::query()->count());
});

test('the figures are cached for 15 minutes with the time they were worked out, and only for admins', function () {
    $first = apsStats();
    expect($first['asOf'])->toBe(now()->toIso8601String());

    User::factory()->create();
    // Still the cached figure (apsStats() adds an admin each call, too).
    expect(apsStats()['totals']['users'])->toBe($first['totals']['users'])
        ->and(apsStats()['asOf'])->toBe($first['asOf']);

    $this->travel(16)->minutes();
    expect(apsStats()['asOf'])->not->toBe($first['asOf']);

    expect(GetPlatformStatsAction::new()->execute(User::factory()->create()))->toBe([]);
});

test('counsellors awaiting verification include ended, revoked and undated verifications with a request waiting', function () {
    $states = [
        'never' => ['verified_at' => null, 'verification_expires_at' => null],
        'ended' => ['verified_at' => now()->subYear(), 'verification_expires_at' => now()->subDay()],
        'revoked' => ['verification_revoked_at' => now()->subDay()],
        'undated' => ['verified_at' => now()->subYear(), 'verification_expires_at' => null],
        'current' => [],
    ];

    foreach ($states as $state) {
        $counsellor = apsCounsellor($state);
        $request = new Request(['type' => RequestTypeEnum::counsellor->value, 'data' => [], 'status' => 'PENDING']);
        $request->from()->associate($counsellor);
        $request->for()->associate($counsellor);
        $request->save();
    }

    // Every one but the currently verified counsellor (a renewal waiting).
    expect(apsStats()['totals']['counsellorsAwaiting'])->toBe(4);
});
