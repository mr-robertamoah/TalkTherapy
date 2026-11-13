<?php

use App\Actions\Transaction\ChargeOrganizationForModelAction;
use App\Actions\Transaction\GenerateCounsellorEarningsAction;
use App\Actions\Transaction\ReconcileStalePendingOrganizationChargesAction;
use App\DTOs\TransactionDTO;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Enums\OrganizationCounsellorCompensationBasisEnum;
use App\Enums\OrganizationCounsellorCompensationTypeEnum;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Enums\SessionStatusEnum;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapyPerPaymentEnum;
use App\Enums\TherapySessionTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Enums\TransactionStatusEnum;
use App\Exceptions\TransactionException;
use App\Models\Counsellor;
use App\Models\CounsellorEarning;
use App\Models\GroupTherapy;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\OrganizationCounsellorCompensation;
use App\Models\OrganizationPaymentInstrument;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// SCRUM-420 part 2: an organisation's card pays for a member's place in a group (or one of its sessions) -- split
// between the group's counsellors, charged per member, with earnings made from the split recorded at charge time.

// A paid group (GHS 150) run by one counsellor per entry of $terms (null: affiliated, but no terms), created by a
// user, with $members more members. The organisation has a card. Returns [organisation, group, counsellors,
// clients -- the creator first].
function ocgGroup(array $terms, int $members = 0, string $per = 'PER_THERAPY', int $maxUsers = 10): array
{
    config(['settings.platform_fee_percentage' => 10]);

    $organization = Organization::factory()->create();
    OrganizationPaymentInstrument::factory()->create(['organization_id' => $organization->id]);

    $creator = User::factory()->adult()->create();
    $group = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
        'session_type' => TherapySessionTypeEnum::once->value,
        'status' => TherapyStatusEnum::in_session->value,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => $per, 'amount' => 150, 'currency' => 'GHS'],
        'max_users' => $maxUsers,
    ]);

    $counsellors = collect($terms)->map(function (?array $compensation) use ($organization, $group) {
        $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()]);
        $affiliation = OrganizationCounsellor::factory()->create([
            'organization_id' => $organization->id,
            'counsellor_id' => $counsellor->id,
            'status' => OrganizationCounsellorStatusEnum::active->value,
        ]);

        if ($compensation !== null) {
            OrganizationCounsellorCompensation::factory()->create(array_merge(['organization_counsellor_id' => $affiliation->id], $compensation));
        }

        $group->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);

        return $counsellor;
    });

    $clients = collect([$creator]);

    for ($i = 0; $i < $members; $i++) {
        $member = User::factory()->adult()->create();
        $group->users()->attach($member->id, ['background_story' => null, 'anonymous' => false]);
        $clients->push($member);
    }

    return [$organization, $group->fresh(), $counsellors, $clients];
}

function ocgFakesCharge(int $amount): void
{
    Http::fake(['*/transaction/charge_authorization' => Http::response([
        'status' => true,
        'data' => ['status' => 'success', 'amount' => $amount, 'currency' => 'GHS', 'gateway_response' => 'Approved'],
    ], 200)]);
}

function ocgCharge(Organization $organization, User $user, $for): Transaction
{
    return ChargeOrganizationForModelAction::new()->execute(TransactionDTO::new()->fromArray([
        'user' => $user, 'for' => $for, 'organization' => $organization,
    ]));
}

function ocgRate(int $percentage): array
{
    return [
        'type' => OrganizationCounsellorCompensationTypeEnum::percentage->value,
        'basis' => OrganizationCounsellorCompensationBasisEnum::counsellorRate->value,
        'percentage' => $percentage,
    ];
}

test('the price is split between the counsellors, each paid their own rate on their part', function () {
    [$organization, $group, $counsellors, $clients] = ocgGroup([ocgRate(80), ocgRate(60)]);
    // 15000 split 7500/7500: 80% = 6000, 60% = 4500; fee 10% of 15000 = 1500, 750 each. 12000 in all.
    ocgFakesCharge(12000);

    $transaction = ocgCharge($organization, $clients[0], $group);

    expect($transaction->status)->toBe(TransactionStatusEnum::success->value)
        ->and($transaction->amount)->toBe(12000)
        ->and($transaction->reference)->toStartWith(ChargeOrganizationForModelAction::REFERENCE_PREFIX)
        ->and($transaction->fresh()->breakdown['lines'])->toBe([
            ['counsellor_id' => $counsellors[0]->id, 'net_amount' => 6000, 'fee_amount' => 750],
            ['counsellor_id' => $counsellors[1]->id, 'net_amount' => 4500, 'fee_amount' => 750],
        ]);

    $earnings = CounsellorEarning::query()->orderBy('id')->get();
    expect($earnings)->toHaveCount(2)
        ->and($earnings->pluck('counsellor_id')->all())->toBe($counsellors->pluck('id')->all())
        ->and($earnings->pluck('net_amount')->all())->toBe([6000, 4500])
        ->and($earnings->pluck('fee_amount')->all())->toBe([750, 750])
        ->and($earnings->sum('gross_amount'))->toBe(12000);
});

test('the split is never sent back to the member who paid', function () {
    [$organization, $group, , $clients] = ocgGroup([ocgRate(80), ocgRate(60)]);
    ocgFakesCharge(12000);

    expect(ocgCharge($organization, $clients[0], $group)->toArray())->not->toHaveKey('breakdown');
});

test('a fixed rate is shared across the group\'s places, the remainder on the first', function () {
    $fixed = ['type' => OrganizationCounsellorCompensationTypeEnum::fixed->value, 'amount' => 5000, 'currency' => 'GHS'];
    [$organization, $group, , $clients] = ocgGroup([$fixed], members: 2, maxUsers: 3);

    // 5000 over 3 places: 1668, 1666, 1666 -- plus the 1500 fee each.
    Http::fake(['*/transaction/charge_authorization' => Http::sequence()
        ->push(['status' => true, 'data' => ['status' => 'success', 'amount' => 3168, 'currency' => 'GHS']])
        ->push(['status' => true, 'data' => ['status' => 'success', 'amount' => 3166, 'currency' => 'GHS']])]);

    expect(ocgCharge($organization, $clients[0], $group)->amount)->toBe(3168)
        ->and(ocgCharge($organization, $clients[2], $group)->amount)->toBe(3166);
});

// The user's decision (2026-11-13): by the group's maximum size, not who's there when each pays -- or a member paying
// early, alone, would have their organisation pay the whole rate, and the organisations more than it in all.
test('a fixed rate is shared by the group\'s maximum size even while it has fewer members', function () {
    $fixed = ['type' => OrganizationCounsellorCompensationTypeEnum::fixed->value, 'amount' => 5000, 'currency' => 'GHS'];
    [$organization, $group, , $clients] = ocgGroup([$fixed], maxUsers: 5);
    ocgFakesCharge(2500);

    // 5000 over 5 places = 1000, plus the 1500 fee.
    expect(ocgCharge($organization, $clients[0], $group)->amount)->toBe(2500);
});

test('each member is charged on their own -- one member having paid doesn\'t stop another', function () {
    [$organization, $group, , $clients] = ocgGroup([ocgRate(80)], members: 1);
    ocgFakesCharge(13500);

    ocgCharge($organization, $clients[0], $group);
    ocgCharge($organization, $clients[1], $group);

    expect(Transaction::query()->where('status', TransactionStatusEnum::success->value)->pluck('user_id')->sort()->values()->all())
        ->toBe($clients->pluck('id')->sort()->values()->all());

    expect(fn () => ocgCharge($organization, $clients[0], $group))->toThrow(TransactionException::class, 'This has already been paid for.');
});

test('a member\'s charge still being confirmed doesn\'t block another member, only them', function () {
    [$organization, $group, , $clients] = ocgGroup([ocgRate(80)], members: 1);
    Transaction::factory()->create([
        'for_type' => GroupTherapy::class,
        'for_id' => $group->id,
        'user_id' => $clients[0]->id,
        'organization_id' => $organization->id,
        'reference' => ChargeOrganizationForModelAction::REFERENCE_PREFIX.'still-confirming',
        'status' => TransactionStatusEnum::pending->value,
    ]);
    ocgFakesCharge(13500);

    expect(ocgCharge($organization, $clients[1], $group)->status)->toBe(TransactionStatusEnum::success->value);
    expect(fn () => ocgCharge($organization, $clients[0], $group))->toThrow(TransactionException::class, 'still being confirmed');
});

test('the charge lock is the member\'s own', function () {
    [$organization, $group, , $clients] = ocgGroup([ocgRate(80)], members: 1);
    ocgFakesCharge(13500);
    $lock = Cache::lock('charge-organization-for:'.GroupTherapy::class.':'.$group->id.':'.$clients[0]->id, 30);
    expect($lock->get())->toBeTrue();

    try {
        expect(ocgCharge($organization, $clients[1], $group)->status)->toBe(TransactionStatusEnum::success->value);
        expect(fn () => ocgCharge($organization, $clients[0], $group))->toThrow(TransactionException::class, 'already being charged');
    } finally {
        $lock->release();
    }
});

test('a counsellor with no terms refuses the whole charge and charges nobody', function () {
    [$organization, $group, , $clients] = ocgGroup([ocgRate(80), null]);
    Http::fake();

    expect(fn () => ocgCharge($organization, $clients[0], $group))
        ->toThrow(TransactionException::class, 'This counsellor has no active compensation terms with this organization.');

    expect(Transaction::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('someone who isn\'t one of the group\'s members is refused', function () {
    [$organization, $group] = ocgGroup([ocgRate(80)]);
    Http::fake();

    expect(fn () => ocgCharge($organization, User::factory()->adult()->create(), $group))
        ->toThrow(TransactionException::class, 'You are not authorized to pay for this via this organization.');

    Http::assertNothingSent();
});

test('a session of a per-session group is charged per member', function () {
    [$organization, $group, $counsellors, $clients] = ocgGroup([ocgRate(80)], members: 1, per: TherapyPerPaymentEnum::session->value);
    $session = Session::factory()->create([
        'for_type' => GroupTherapy::class, 'for_id' => $group->id, 'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'start_time' => now()->addDay(), 'end_time' => now()->addDay()->addHour(),
    ]);
    ocgFakesCharge(13500);

    $first = ocgCharge($organization, $clients[0], $session);
    $second = ocgCharge($organization, $clients[1], $session);

    expect($first->for_type)->toBe(Session::class)
        ->and($second->for_id)->toBe($session->id)
        ->and(CounsellorEarning::query()->where('counsellor_id', $counsellors[0]->id)->sum('net_amount'))->toBe(24000);
});

test('earnings follow the split recorded at charge time, even if the terms changed since', function () {
    [$organization, $group, $counsellors, $clients] = ocgGroup([ocgRate(80), ocgRate(60)]);
    $transaction = Transaction::factory()->create([
        'for_type' => GroupTherapy::class,
        'for_id' => $group->id,
        'user_id' => $clients[0]->id,
        'organization_id' => $organization->id,
        'amount' => 12000,
        'status' => TransactionStatusEnum::success->value,
        'breakdown' => ['lines' => [
            ['counsellor_id' => $counsellors[0]->id, 'net_amount' => 6000, 'fee_amount' => 750],
            ['counsellor_id' => $counsellors[1]->id, 'net_amount' => 4500, 'fee_amount' => 750],
        ]],
    ]);
    OrganizationCounsellorCompensation::query()->update(['percentage' => 10]);

    GenerateCounsellorEarningsAction::new()->execute($transaction);

    expect(CounsellorEarning::query()->orderBy('id')->pluck('net_amount')->all())->toBe([6000, 4500]);
});

test('a recorded split over the amount charged pays nothing out and is flagged', function () {
    Log::spy();
    [$organization, $group, $counsellors, $clients] = ocgGroup([ocgRate(80)]);
    $transaction = Transaction::factory()->create([
        'for_type' => GroupTherapy::class,
        'for_id' => $group->id,
        'user_id' => $clients[0]->id,
        'organization_id' => $organization->id,
        'amount' => 1000,
        'status' => TransactionStatusEnum::success->value,
        'breakdown' => ['lines' => [['counsellor_id' => $counsellors[0]->id, 'net_amount' => 6000, 'fee_amount' => 750]]],
    ]);

    GenerateCounsellorEarningsAction::new()->execute($transaction);

    expect(CounsellorEarning::query()->count())->toBe(0);
    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'does not add up'))->once();
});

test('an organisation-labelled group payment from before (no split recorded) still makes no earnings', function () {
    [$organization, $group, , $clients] = ocgGroup([ocgRate(80)]);
    $transaction = Transaction::factory()->create([
        'for_type' => GroupTherapy::class,
        'for_id' => $group->id,
        'user_id' => $clients[0]->id,
        'organization_id' => $organization->id,
        'amount' => 15000,
        'status' => TransactionStatusEnum::success->value,
    ]);

    GenerateCounsellorEarningsAction::new()->execute($transaction);

    expect(CounsellorEarning::query()->count())->toBe(0);
});

test('a group session that didn\'t take place isn\'t charged', function () {
    [$organization, $group, , $clients] = ocgGroup([ocgRate(80)], per: TherapyPerPaymentEnum::session->value);
    $session = Session::factory()->create(['for_type' => GroupTherapy::class, 'for_id' => $group->id, 'payment_type' => TherapyPaymentTypeEnum::paid->value, 'status' => SessionStatusEnum::failed->value]);
    Http::fake();

    expect(fn () => ocgCharge($organization, $clients[0], $session))->toThrow(TransactionException::class, 'did not take place');
    Http::assertNothingSent();
});

test('a member who joined after a session ended can\'t have their organisation pay for it', function () {
    [$organization, $group, , $clients] = ocgGroup([ocgRate(80)], members: 1, per: TherapyPerPaymentEnum::session->value);
    $group->users()->updateExistingPivot($clients[1]->id, ['created_at' => now()]);
    $session = Session::factory()->create([
        'for_type' => GroupTherapy::class, 'for_id' => $group->id, 'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'start_time' => now()->subDays(2), 'end_time' => now()->subDays(2)->addHour(),
    ]);
    Http::fake();

    expect(fn () => ocgCharge($organization, $clients[1], $session))
        ->toThrow(TransactionException::class, 'You are not authorized to pay for this via this organization.');
    Http::assertNothingSent();
});

test('a checkout the member just started on their own card blocks an organisation charge for the same place', function () {
    [$organization, $group, , $clients] = ocgGroup([ocgRate(80)]);
    Transaction::factory()->create([
        'for_type' => GroupTherapy::class, 'for_id' => $group->id, 'user_id' => $clients[0]->id,
        'reference' => 'personal-checkout', 'status' => TransactionStatusEnum::pending->value,
    ]);
    Http::fake();

    expect(fn () => ocgCharge($organization, $clients[0], $group))->toThrow(TransactionException::class, 'still open');
    Http::assertNothingSent();
});

test('a one-to-one therapy charge records its split too', function () {
    [$organization, $group, $counsellors, $clients] = ocgGroup([ocgRate(80)]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $clients[0]->id, 'counsellor_id' => $counsellors[0]->id,
        'session_type' => TherapySessionTypeEnum::once->value, 'status' => TherapyStatusEnum::in_session->value,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => TherapyPerPaymentEnum::therapy->value, 'amount' => 150, 'currency' => 'GHS'],
    ]);
    ocgFakesCharge(13500);

    expect(ocgCharge($organization, $clients[0], $therapy)->fresh()->breakdown['lines'])
        ->toBe([['counsellor_id' => $counsellors[0]->id, 'net_amount' => 12000, 'fee_amount' => 1500]]);
});

test('an amount over the recorded split goes to the first earning written, as fee -- past a counsellor who\'s gone', function () {
    Log::spy();
    [$organization, $group, $counsellors, $clients] = ocgGroup([ocgRate(80), ocgRate(60)]);
    $transaction = Transaction::factory()->create([
        'for_type' => GroupTherapy::class, 'for_id' => $group->id, 'user_id' => $clients[0]->id,
        'organization_id' => $organization->id, 'amount' => 12200, 'status' => TransactionStatusEnum::success->value,
        'breakdown' => ['lines' => [
            ['counsellor_id' => 999999, 'net_amount' => 1000, 'fee_amount' => 100],
            ['counsellor_id' => $counsellors[0]->id, 'net_amount' => 6000, 'fee_amount' => 750],
            ['counsellor_id' => $counsellors[1]->id, 'net_amount' => 3500, 'fee_amount' => 750],
        ]],
    ]);

    GenerateCounsellorEarningsAction::new()->execute($transaction);

    // 100 more than the split: the first line's counsellor is gone, so it goes on the next one's fee.
    expect(CounsellorEarning::query()->orderBy('id')->pluck('fee_amount')->all())->toBe([850, 750]);
});

test('the stale-charge sweep checks a whole-group organisation charge with Paystack', function () {
    [$organization, $group, , $clients] = ocgGroup([ocgRate(80)]);
    $transaction = Transaction::factory()->create([
        'for_type' => GroupTherapy::class, 'for_id' => $group->id, 'user_id' => $clients[0]->id,
        'organization_id' => $organization->id, 'amount' => 13500, 'currency' => 'GHS',
        'reference' => ChargeOrganizationForModelAction::REFERENCE_PREFIX.'stale', 'status' => TransactionStatusEnum::pending->value,
        'created_at' => now()->subHour(),
    ]);
    Http::fake(['*/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'failed', 'amount' => 13500, 'currency' => 'GHS']])]);

    ReconcileStalePendingOrganizationChargesAction::new()->execute();

    expect($transaction->fresh()->status)->toBe(TransactionStatusEnum::failed->value);
});

test('a charge whose split can\'t be worked out is refused, not charged', function () {
    [$organization, $group, , $clients] = ocgGroup([ocgRate(80)]);
    $group->update(['payment_data' => ['per' => TherapyPerPaymentEnum::therapy->value, 'currency' => 'GHS']]);
    Http::fake();

    expect(fn () => ocgCharge($organization, $clients[0], $group))->toThrow(TransactionException::class, 'We could not work out this organization charge.');
    Http::assertNothingSent();
});
