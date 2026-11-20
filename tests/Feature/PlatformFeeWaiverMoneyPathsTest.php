<?php

use App\Actions\Organization\RecordOrganizationInvoiceLineForSessionAction;
use App\Actions\Organization\SettleOrganizationInvoiceAction;
use App\Actions\Transaction\ChargeOrganizationForModelAction;
use App\Actions\Transaction\GenerateCounsellorEarningsAction;
use App\Actions\Transaction\RecordRefundStatusAction;
use App\DTOs\TransactionDTO;
use App\Enums\CounsellorEarningStatusEnum;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\OrganizationCounsellorCompensationBasisEnum;
use App\Enums\OrganizationCounsellorCompensationTypeEnum;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Enums\OrganizationMemberBillingModeEnum;
use App\Enums\RefundStatusEnum;
use App\Enums\RefundStatusSourceEnum;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapyPerPaymentEnum;
use App\Enums\TherapySessionTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Enums\TransactionStatusEnum;
use App\Models\Counsellor;
use App\Models\CounsellorEarning;
use App\Models\GroupTherapy;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\OrganizationCounsellorCompensation;
use App\Models\OrganizationInvoice;
use App\Models\OrganizationInvoiceLine;
use App\Models\OrganizationMember;
use App\Models\OrganizationMemberBillingConfig;
use App\Models\OrganizationPaymentInstrument;
use App\Models\PlatformFeeWaiver;
use App\Models\Refund;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

// TT-7.10a/SCRUM-467: during a fee-free period no money path takes a platform fee -- a counsellor keeps the whole of
// a personal payment, an organisation pays nothing on top -- and each earning or invoice line records the waiver and
// what it waived. The decision is made once (checkout start, or when a retainer line is recorded) and never again.

beforeEach(function () {
    config(['settings.platform_fee_percentage' => 10]);
});

function pfwCounsellor(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()->adult()]);
}

function pfwTherapy(Counsellor $counsellor, User $client): Therapy
{
    return Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id,
        'session_type' => TherapySessionTypeEnum::once->value, 'status' => TherapyStatusEnum::in_session->value,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => TherapyPerPaymentEnum::therapy->value, 'amount' => 100, 'currency' => 'GHS'],
    ]);
}

// A paid group (GHS 150), split equally between its counsellors when a member pays for it personally.
function pfwGroup(int $counsellors, ?User $creator = null): GroupTherapy
{
    $group = GroupTherapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => ($creator ?? User::factory()->adult()->create())->id,
        'session_type' => TherapySessionTypeEnum::once->value, 'status' => TherapyStatusEnum::in_session->value,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 150, 'currency' => 'GHS', 'shareEqually' => true],
        'max_users' => 10,
    ]);

    for ($i = 0; $i < $counsellors; $i++) {
        $group->counsellors()->attach(pfwCounsellor()->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);
    }

    return $group->fresh();
}

function pfwPersonalPayment($for, int $amount, array $overrides = []): Transaction
{
    return Transaction::factory()->create(array_merge([
        'for_type' => $for::class, 'for_id' => $for->id, 'amount' => $amount, 'currency' => 'GHS',
        'status' => TransactionStatusEnum::success->value,
    ], $overrides));
}

// An organisation with a card and an owner, paying each counsellor $percentage of their part of the listed rate.
function pfwOrganization(iterable $counsellors, int $percentage = 80): Organization
{
    $organization = Organization::factory()->create(['is_consumer' => true, 'verified_at' => now()]);
    $organization->admins()->attach(User::factory()->create()->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    OrganizationPaymentInstrument::factory()->create(['organization_id' => $organization->id, 'currency' => 'GHS']);

    foreach ($counsellors as $counsellor) {
        $affiliation = OrganizationCounsellor::factory()->create([
            'organization_id' => $organization->id, 'counsellor_id' => $counsellor->id,
            'status' => OrganizationCounsellorStatusEnum::active->value,
        ]);
        OrganizationCounsellorCompensation::factory()->create([
            'organization_counsellor_id' => $affiliation->id,
            'type' => OrganizationCounsellorCompensationTypeEnum::percentage->value,
            'basis' => OrganizationCounsellorCompensationBasisEnum::counsellorRate->value,
            'percentage' => $percentage,
        ]);
    }

    return $organization;
}

function pfwOrgCharge(Organization $organization, User $member, $for, int $expectedAmount): Transaction
{
    Http::fake(['*/transaction/charge_authorization' => Http::response([
        'status' => true,
        'data' => ['status' => 'success', 'amount' => $expectedAmount, 'currency' => 'GHS', 'gateway_response' => 'Approved'],
    ], 200)]);

    return ChargeOrganizationForModelAction::new()->execute(TransactionDTO::new()->fromArray([
        'user' => $member, 'for' => $for, 'organization' => $organization,
    ]));
}

function pfwRetainerMember(Organization $organization): User
{
    $member = User::factory()->create();
    $membership = OrganizationMember::factory()->create(['organization_id' => $organization->id, 'user_id' => $member->id]);
    OrganizationMemberBillingConfig::factory()->create([
        'organization_member_id' => $membership->id,
        'mode' => OrganizationMemberBillingModeEnum::retainer->value,
    ]);

    return $member;
}

function pfwExpectInvariant(Transaction $transaction): void
{
    $earnings = CounsellorEarning::query()->where('transaction_id', $transaction->id)->get();

    expect($earnings->sum('net_amount') + $earnings->sum('fee_amount'))->toBe($transaction->amount);
}

// Personal payments ------------------------------------------------------------------------------------------------

test('a personal payment for a therapy in a fee-free period: the counsellor keeps it all', function () {
    $waiver = PlatformFeeWaiver::factory()->create();
    $counsellor = pfwCounsellor();
    $transaction = pfwPersonalPayment(pfwTherapy($counsellor, User::factory()->adult()->create()), 10000);

    GenerateCounsellorEarningsAction::new()->execute($transaction);

    $earning = $transaction->earnings()->sole();
    expect($earning->gross_amount)->toBe(10000)
        ->and($earning->fee_amount)->toBe(0)
        ->and($earning->net_amount)->toBe(10000)
        ->and($earning->platform_fee_waiver_id)->toBe($waiver->id)
        ->and($earning->waived_fee_amount)->toBe(1000)
        ->and($earning->platformFeeWaiver->is($waiver))->toBeTrue();
    pfwExpectInvariant($transaction);
});

test('a personal payment started before the period, succeeding during it, pays the fee', function () {
    PlatformFeeWaiver::factory()->between(now()->subHour(), null)->create();
    $transaction = pfwPersonalPayment(pfwTherapy(pfwCounsellor(), User::factory()->adult()->create()), 10000, ['created_at' => now()->subHours(2)]);

    GenerateCounsellorEarningsAction::new()->execute($transaction);

    $earning = $transaction->earnings()->sole();
    expect($earning->fee_amount)->toBe(1000)
        ->and($earning->net_amount)->toBe(9000)
        ->and($earning->platform_fee_waiver_id)->toBeNull()
        ->and($earning->waived_fee_amount)->toBe(0);
});

test('a personal payment started in the period pays no fee even if it succeeds after the period ended', function () {
    $waiver = PlatformFeeWaiver::factory()->between(now()->subDay(), now()->subHour())->create();
    $transaction = pfwPersonalPayment(pfwTherapy(pfwCounsellor(), User::factory()->adult()->create()), 10000, ['created_at' => now()->subHours(2)]);

    GenerateCounsellorEarningsAction::new()->execute($transaction);

    expect($transaction->earnings()->sole()->only('fee_amount', 'platform_fee_waiver_id', 'waived_fee_amount'))
        ->toBe(['fee_amount' => 0, 'platform_fee_waiver_id' => $waiver->id, 'waived_fee_amount' => 1000]);
});

test('a counsellor\'s own waiver frees only their earnings from a personal payment', function () {
    $group = pfwGroup(2);
    [$covered, $other] = $group->activeCounsellors()->values()->all();
    $waiver = PlatformFeeWaiver::factory()->forCounsellor($covered)->create();
    $transaction = pfwPersonalPayment($group, 10000);

    GenerateCounsellorEarningsAction::new()->execute($transaction);

    $earnings = $transaction->earnings()->get()->keyBy('counsellor_id');
    expect($earnings[$covered->id]->only('fee_amount', 'net_amount', 'platform_fee_waiver_id', 'waived_fee_amount'))
        ->toBe(['fee_amount' => 0, 'net_amount' => 5000, 'platform_fee_waiver_id' => $waiver->id, 'waived_fee_amount' => 500])
        ->and($earnings[$other->id]->only('fee_amount', 'net_amount', 'platform_fee_waiver_id', 'waived_fee_amount'))
        ->toBe(['fee_amount' => 500, 'net_amount' => 4500, 'platform_fee_waiver_id' => null, 'waived_fee_amount' => 0]);
    pfwExpectInvariant($transaction);
});

test('a personal payment for a group in a fee-free period: every counsellor keeps their whole share', function () {
    $waiver = PlatformFeeWaiver::factory()->create();
    $transaction = pfwPersonalPayment(pfwGroup(3), 10000);

    GenerateCounsellorEarningsAction::new()->execute($transaction);

    $earnings = $transaction->earnings()->orderBy('id')->get();
    expect($earnings->pluck('fee_amount')->all())->toBe([0, 0, 0])
        ->and($earnings->pluck('net_amount')->all())->toBe([3334, 3333, 3333])
        ->and($earnings->pluck('platform_fee_waiver_id')->unique()->all())->toBe([$waiver->id])
        ->and($earnings->pluck('waived_fee_amount')->all())->toBe([333, 333, 333]);
    pfwExpectInvariant($transaction);
});

test('a group payment\'s earnings look the waivers up once, however many counsellors it has', function (int $counsellors) {
    PlatformFeeWaiver::factory()->create();
    $transaction = pfwPersonalPayment(pfwGroup($counsellors), 12000);
    $waiverQueries = 0;
    DB::listen(function ($query) use (&$waiverQueries) {
        $waiverQueries += str_contains($query->sql, 'platform_fee_waivers') ? 1 : 0;
    });

    GenerateCounsellorEarningsAction::new()->execute($transaction);

    expect($transaction->earnings()->count())->toBe($counsellors)
        ->and($waiverQueries)->toBe(1);
})->with([1, 4]);

// Organisation card charges --------------------------------------------------------------------------------------

test('an organisation card charge for a group place in a fee-free period adds no fee on top', function () {
    $waiver = PlatformFeeWaiver::factory()->create();
    $member = User::factory()->adult()->create();
    $group = pfwGroup(2, $member);
    $organization = pfwOrganization($group->activeCounsellors());

    // 15000 split 7500/7500, 80% each = 6000 + 6000; no fee (it would have been 750 each).
    $transaction = pfwOrgCharge($organization, $member, $group, 12000);

    expect($transaction->status)->toBe(TransactionStatusEnum::success->value)
        ->and($transaction->amount)->toBe(12000)
        ->and($transaction->fresh()->breakdown['lines'])->each(fn ($line) => $line->toMatchArray([
            'net_amount' => 6000, 'fee_amount' => 0, 'platform_fee_waiver_id' => $waiver->id, 'waived_fee_amount' => 750,
        ]));

    $earnings = $transaction->earnings()->get();
    expect($earnings)->toHaveCount(2)
        ->and($earnings->pluck('fee_amount')->all())->toBe([0, 0])
        ->and($earnings->pluck('gross_amount')->all())->toBe([6000, 6000])
        ->and($earnings->pluck('platform_fee_waiver_id')->unique()->all())->toBe([$waiver->id])
        ->and($earnings->pluck('waived_fee_amount')->all())->toBe([750, 750]);
    pfwExpectInvariant($transaction);
});

test('an organisation card charge for a one-to-one therapy in a fee-free period adds no fee on top', function () {
    $waiver = PlatformFeeWaiver::factory()->create();
    $member = User::factory()->adult()->create();
    $counsellor = pfwCounsellor();
    $organization = pfwOrganization([$counsellor]);

    // Listed 10000, 80% = 8000; the fee would have been 1000.
    $transaction = pfwOrgCharge($organization, $member, pfwTherapy($counsellor, $member), 8000);

    $earning = $transaction->earnings()->sole();
    expect($transaction->amount)->toBe(8000)
        ->and($earning->only('net_amount', 'fee_amount', 'platform_fee_waiver_id', 'waived_fee_amount'))
        ->toBe(['net_amount' => 8000, 'fee_amount' => 0, 'platform_fee_waiver_id' => $waiver->id, 'waived_fee_amount' => 1000]);
    pfwExpectInvariant($transaction);
});

test('an organisation card charge is decided at the moment it is made, and its created_at is that moment', function () {
    $this->freezeSecond();
    PlatformFeeWaiver::factory()->between(now()->addSecond(), null)->create();
    $member = User::factory()->adult()->create();
    $counsellor = pfwCounsellor();

    $transaction = pfwOrgCharge(pfwOrganization([$counsellor]), $member, pfwTherapy($counsellor, $member), 9000);

    expect($transaction->amount)->toBe(9000)
        ->and($transaction->created_at->equalTo(now()))->toBeTrue()
        ->and($transaction->earnings()->sole()->platform_fee_waiver_id)->toBeNull();
});

test('refunding a waived organisation charge reverses its earnings and decides nothing again', function () {
    Notification::fake();
    $waiver = PlatformFeeWaiver::factory()->create();
    $member = User::factory()->adult()->create();
    $group = pfwGroup(2, $member);
    $transaction = pfwOrgCharge(pfwOrganization($group->activeCounsellors()), $member, $group, 12000);
    $waiver->update(['ends_at' => now()]);
    $this->travel(1)->hour();

    $refund = Refund::factory()->create(['transaction_id' => $transaction->id, 'status' => RefundStatusEnum::pending->value]);
    RecordRefundStatusAction::new()->execute($refund, RefundStatusEnum::success->value, RefundStatusSourceEnum::initiate->value);

    $earnings = CounsellorEarning::query()->get();
    expect($earnings)->toHaveCount(2)
        ->and($earnings->pluck('status')->unique()->all())->toBe([CounsellorEarningStatusEnum::reversed->value])
        ->and($earnings->pluck('fee_amount')->all())->toBe([0, 0])
        ->and($earnings->pluck('platform_fee_waiver_id')->unique()->all())->toBe([$waiver->id])
        ->and($earnings->pluck('waived_fee_amount')->all())->toBe([750, 750]);
});

// Retainer invoices ----------------------------------------------------------------------------------------------

test('a retainer line recorded in a fee-free period has no fee, and settlement copies it as recorded', function () {
    Bus::fake();
    $this->travelTo(now()->startOfMonth()->addDays(10));
    $waiver = PlatformFeeWaiver::factory()->create();
    $counsellor = pfwCounsellor();
    $organization = pfwOrganization([$counsellor]);
    $therapy = pfwTherapy($counsellor, pfwRetainerMember($organization));
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);

    RecordOrganizationInvoiceLineForSessionAction::new()->execute($session);

    $line = OrganizationInvoiceLine::query()->sole();
    expect($line->only('net_amount', 'fee_amount', 'platform_fee_waiver_id', 'waived_fee_amount'))
        ->toBe(['net_amount' => 8000, 'fee_amount' => 0, 'platform_fee_waiver_id' => $waiver->id, 'waived_fee_amount' => 1000])
        ->and($line->platformFeeWaiver->is($waiver))->toBeTrue();

    // The period ends before settlement: what was recorded stands.
    $waiver->update(['ends_at' => now()]);
    $this->travelTo(now()->addMonth()->startOfMonth()->addDay());

    $transaction = SettleOrganizationInvoiceAction::new()->execute(OrganizationInvoice::query()->sole());
    expect($transaction->amount)->toBe(8000)
        ->and($transaction->amount)->toBe((int) OrganizationInvoiceLine::query()->get()->sum(fn ($line) => $line->net_amount + $line->fee_amount));

    $transaction->update(['status' => TransactionStatusEnum::success->value]);
    GenerateCounsellorEarningsAction::new()->execute($transaction);

    $earning = $transaction->earnings()->sole();
    expect($earning->only('gross_amount', 'net_amount', 'fee_amount', 'platform_fee_waiver_id', 'waived_fee_amount'))
        ->toBe(['gross_amount' => 8000, 'net_amount' => 8000, 'fee_amount' => 0, 'platform_fee_waiver_id' => $waiver->id, 'waived_fee_amount' => 1000]);
    pfwExpectInvariant($transaction);
});

test('a retainer line recorded outside any period pays the fee, even if a period has started by settlement', function () {
    Bus::fake();
    $this->travelTo(now()->startOfMonth()->addDays(10));
    $counsellor = pfwCounsellor();
    $organization = pfwOrganization([$counsellor]);
    $therapy = pfwTherapy($counsellor, pfwRetainerMember($organization));

    RecordOrganizationInvoiceLineForSessionAction::new()->execute(Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]));
    $this->travelTo(now()->addMonth()->startOfMonth()->addDay());
    PlatformFeeWaiver::factory()->create();

    $transaction = SettleOrganizationInvoiceAction::new()->execute(OrganizationInvoice::query()->sole());
    $transaction->update(['status' => TransactionStatusEnum::success->value]);
    GenerateCounsellorEarningsAction::new()->execute($transaction);

    expect($transaction->amount)->toBe(9000)
        ->and($transaction->earnings()->sole()->only('fee_amount', 'platform_fee_waiver_id', 'waived_fee_amount'))
        ->toBe(['fee_amount' => 1000, 'platform_fee_waiver_id' => null, 'waived_fee_amount' => 0]);
});

test('a held session whose fee can\'t be worked out records no line, logged as an error', function () {
    Log::spy();
    $counsellor = pfwCounsellor();
    $organization = pfwOrganization([$counsellor]);
    $therapy = pfwTherapy($counsellor, pfwRetainerMember($organization));
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);
    Schema::drop('platform_fee_waivers');

    RecordOrganizationInvoiceLineForSessionAction::new()->execute($session);

    expect(OrganizationInvoiceLine::query()->count())->toBe(0);
    Log::shouldHaveReceived('error')->withArgs(fn ($message) => str_contains($message, 'Unable to resolve the platform fee'))->once();
});
