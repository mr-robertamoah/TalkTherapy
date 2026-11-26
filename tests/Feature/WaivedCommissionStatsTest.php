<?php

use App\Actions\Admin\GetPlatformStatsAction;
use App\Enums\AdministratorTypeEnum;
use App\Enums\CounsellorEarningStatusEnum;
use App\Enums\OrganizationInvoiceStatusEnum;
use App\Enums\TransactionStatusEnum;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\CounsellorEarning;
use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Models\OrganizationInvoiceLine;
use App\Models\PlatformFeeWaiver;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

// SCRUM-469: the commission given up under fee waivers in the admin platform stats (GetPlatformStatsAction's
// `commissionWaived`) -- per currency, platform-wide vs per-counsellor, each waived amount counted once. Helpers are
// file-local and wcs-prefixed (--parallel).

beforeEach(function () {
    Cache::flush();
    // A Wednesday: "this week" is from Monday 16 Nov, "last week" from Monday 9 Nov.
    $this->travelTo(Carbon::parse('2026-11-18 12:00:00'));
});

const WCS_THIS_WEEK = '2026-11-17 10:00:00';

const WCS_LAST_WEEK = '2026-11-10 10:00:00';

function wcsAdmin(): User
{
    return User::factory()->adult()->has(Administrator::factory()->state(['type' => AdministratorTypeEnum::normal->value]))->create();
}

function wcsCounsellor(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
}

function wcsTransaction(array $attributes = []): Transaction
{
    $client = User::factory()->adult()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id]);

    return Transaction::factory()->create([
        'user_id' => $client->id, 'for_type' => Therapy::class, 'for_id' => $therapy->id,
        'status' => TransactionStatusEnum::success->value, ...$attributes,
    ]);
}

function wcsPlatformWaiver(): PlatformFeeWaiver
{
    return PlatformFeeWaiver::factory()->between(Carbon::parse('2026-11-01 00:00:00'), Carbon::parse('2026-11-30 00:00:00'))->create();
}

function wcsCounsellorWaiver(Counsellor $counsellor): PlatformFeeWaiver
{
    return PlatformFeeWaiver::factory()->forCounsellor($counsellor)->between(Carbon::parse('2026-11-01 00:00:00'), null)->create();
}

function wcsEarning(?PlatformFeeWaiver $waiver, int $waived, string $currency = 'GHS', array $attributes = []): CounsellorEarning
{
    return CounsellorEarning::factory()->create([
        'transaction_id' => $attributes['transaction_id'] ?? wcsTransaction()->id,
        'counsellor_id' => $waiver?->counsellor_id ?? wcsCounsellor()->id,
        'gross_amount' => 10000, 'fee_amount' => 0, 'net_amount' => 10000,
        'platform_fee_waiver_id' => $waiver?->id, 'waived_fee_amount' => $waived,
        'currency' => $currency, 'status' => CounsellorEarningStatusEnum::pending->value, 'created_at' => WCS_THIS_WEEK,
        ...$attributes,
    ]);
}

function wcsInvoiceLine(PlatformFeeWaiver $waiver, int $waived, string $invoiceStatus, string $at = WCS_THIS_WEEK, string $currency = 'GHS'): OrganizationInvoiceLine
{
    $invoice = OrganizationInvoice::factory()->create(['status' => $invoiceStatus, 'currency' => $currency]);

    return OrganizationInvoiceLine::factory()->create([
        'organization_invoice_id' => $invoice->id,
        'counsellor_id' => $waiver->counsellor_id ?? wcsCounsellor()->id,
        'net_amount' => 5000, 'fee_amount' => 0, 'currency' => $currency,
        'platform_fee_waiver_id' => $waiver->id, 'waived_fee_amount' => $waived, 'created_at' => $at,
    ]);
}

// The earning settling an invoice makes for a line: the line's figures copied, the line linked.
function wcsSettledEarning(OrganizationInvoiceLine $line, string $status = CounsellorEarningStatusEnum::pending->value, string $at = WCS_THIS_WEEK): CounsellorEarning
{
    return CounsellorEarning::factory()->create([
        'transaction_id' => wcsTransaction(['for_type' => OrganizationInvoice::class, 'for_id' => $line->organization_invoice_id])->id,
        'counsellor_id' => $line->counsellor_id, 'organization_invoice_line_id' => $line->id,
        'gross_amount' => $line->net_amount, 'fee_amount' => 0, 'net_amount' => $line->net_amount,
        'platform_fee_waiver_id' => $line->platform_fee_waiver_id, 'waived_fee_amount' => $line->waived_fee_amount,
        'currency' => $line->currency, 'status' => $status, 'created_at' => $at,
    ]);
}

// An organisation card charge, its breakdown carrying the waiver as ChargeOrganizationForModelAction stores it.
function wcsOrgCharge(PlatformFeeWaiver $waiver, Counsellor $counsellor, int $waived, string $status): Transaction
{
    return wcsTransaction([
        'organization_id' => Organization::factory()->create()->id,
        'amount' => 5000, 'currency' => 'GHS', 'status' => $status, 'created_at' => WCS_THIS_WEEK,
        'breakdown' => ['lines' => [[
            'counsellor_id' => $counsellor->id, 'net_amount' => 5000, 'fee_amount' => 0,
            'platform_fee_waiver_id' => $waiver->id, 'waived_fee_amount' => $waived,
        ]]],
    ]);
}

function wcsWaived(string $period = 'week', string $which = 'current'): ?array
{
    return GetPlatformStatsAction::new()->execute(wcsAdmin())['periods'][$period][$which]['commissionWaived'] ?? null;
}

test('the commission given up is per currency, split between platform-wide and per-counsellor waivers', function () {
    $platform = wcsPlatformWaiver();
    $counsellorWaiver = wcsCounsellorWaiver(wcsCounsellor());

    wcsEarning($platform, 1000, 'GHS');
    wcsEarning($platform, 250, 'GHS', ['status' => CounsellorEarningStatusEnum::paidOut->value]);
    wcsEarning($platform, 300, 'USD');
    wcsEarning($counsellorWaiver, 200, 'GHS');
    wcsInvoiceLine($counsellorWaiver, 50, OrganizationInvoiceStatusEnum::open->value, currency: 'USD');
    // An ordinary earning: the fee was taken, nothing waived.
    wcsEarning(null, 0, 'GHS', ['fee_amount' => 1000]);

    expect(wcsWaived())->toBe([
        'total' => ['GHS' => 1450, 'USD' => 350],
        'platformWide' => ['GHS' => 1250, 'USD' => 300],
        'perCounsellor' => ['GHS' => 200, 'USD' => 50],
        'unattributed' => [],
    ]);
});

test('an organisation charge or invoice line is counted once, never again through the earning made from it', function () {
    $platform = wcsPlatformWaiver();
    $counsellor = wcsCounsellor();

    // A successful card charge: the breakdown and the earning made from it both store the 500.
    $charge = wcsOrgCharge($platform, $counsellor, 500, TransactionStatusEnum::success->value);
    wcsEarning($platform, 500, 'GHS', ['transaction_id' => $charge->id, 'counsellor_id' => $counsellor->id]);
    // A charge still pending: no earning yet, and no money taken -- its breakdown counts for nothing.
    wcsOrgCharge($platform, $counsellor, 999, TransactionStatusEnum::pending->value);
    wcsOrgCharge($platform, $counsellor, 999, TransactionStatusEnum::failed->value);

    // A settled retainer line: the line and its earning both store the 400.
    wcsSettledEarning(wcsInvoiceLine($platform, 400, OrganizationInvoiceStatusEnum::settled->value));
    // A line on an invoice not yet settled: no earning yet, still counted.
    wcsInvoiceLine($platform, 100, OrganizationInvoiceStatusEnum::open->value);
    wcsInvoiceLine($platform, 30, OrganizationInvoiceStatusEnum::pending->value);

    expect(wcsWaived()['total'])->toBe(['GHS' => 1030])
        ->and(wcsWaived('all')['platformWide'])->toBe(['GHS' => 1030]);
});

test('a retainer line stays in the period it was recorded in when its invoice settles later', function () {
    $platform = wcsPlatformWaiver();

    wcsSettledEarning(wcsInvoiceLine($platform, 400, OrganizationInvoiceStatusEnum::settled->value, WCS_LAST_WEEK), at: WCS_THIS_WEEK);

    expect(wcsWaived('week', 'current')['total'])->toBe([])
        ->and(wcsWaived('week', 'previous')['total'])->toBe(['GHS' => 400])
        ->and(wcsWaived('month', 'current')['total'])->toBe(['GHS' => 400]);
});

test('reversed earnings, failed invoices and lines whose earning was reversed are left out', function () {
    $platform = wcsPlatformWaiver();

    wcsEarning($platform, 700, 'GHS', ['status' => CounsellorEarningStatusEnum::reversed->value]);
    wcsInvoiceLine($platform, 800, OrganizationInvoiceStatusEnum::failed->value);
    wcsSettledEarning(wcsInvoiceLine($platform, 900, OrganizationInvoiceStatusEnum::settled->value), CounsellorEarningStatusEnum::reversed->value);
    wcsEarning($platform, 10, 'GHS');

    expect(wcsWaived('all')['total'])->toBe(['GHS' => 10]);
});

test('a waived amount stored without its waiver is unattributed, and the parts add up to the total', function () {
    $platform = wcsPlatformWaiver();
    $counsellorWaiver = wcsCounsellorWaiver(wcsCounsellor());

    wcsEarning($platform, 400, 'GHS');
    wcsEarning($counsellorWaiver, 150, 'GHS');
    // As GenerateCounsellorEarningsAction records a fully waived organisation charge whose amount exceeded its
    // breakdown (the surplus taken as a fee drops the waiver id, the waived amount stays), or a waiver since gone.
    wcsEarning(null, 75, 'GHS', ['fee_amount' => 20]);
    wcsEarning(null, 30, 'USD');

    $waived = wcsWaived();

    expect($waived['total'])->toBe(['GHS' => 625, 'USD' => 30])
        ->and($waived['platformWide'])->toBe(['GHS' => 400])
        ->and($waived['perCounsellor'])->toBe(['GHS' => 150])
        ->and($waived['unattributed'])->toBe(['GHS' => 75, 'USD' => 30]);

    foreach ($waived['total'] as $currency => $total) {
        expect(($waived['platformWide'][$currency] ?? 0) + ($waived['perCounsellor'][$currency] ?? 0) + ($waived['unattributed'][$currency] ?? 0))->toBe($total);
    }
});

test('with nothing waived every figure is empty', function () {
    wcsPlatformWaiver();
    wcsEarning(null, 0, 'GHS', ['fee_amount' => 1000]);

    $empty = ['total' => [], 'platformWide' => [], 'perCounsellor' => [], 'unattributed' => []];

    expect(wcsWaived('week'))->toBe($empty)
        ->and(wcsWaived('week', 'previous'))->toBe($empty)
        ->and(wcsWaived('month'))->toBe($empty)
        ->and(wcsWaived('all'))->toBe($empty);
});

test('only administrators get the figures, and the overview page carries them', function () {
    wcsEarning(wcsPlatformWaiver(), 1200, 'GHS');

    $user = User::factory()->adult()->create();
    expect(GetPlatformStatsAction::new()->execute($user))->toBe([]);
    $this->actingAs($user)->get(route('administrator'))
        ->assertRedirect(route('home'))
        ->assertSessionHas('message', EnsureUserIsAdmin::MESSAGE);

    $this->actingAs(wcsAdmin())->get(route('administrator'))->assertInertia(fn ($page) => $page
        ->component('Admin/Overview')
        ->where('stats.periods.week.current.commissionWaived.total', ['GHS' => 1200])
        ->where('stats.periods.week.current.commissionWaived.platformWide', ['GHS' => 1200])
        ->where('stats.periods.all.current.commissionWaived.perCounsellor', [])
        ->where('stats.periods.all.current.commissionWaived.unattributed', []));
});
