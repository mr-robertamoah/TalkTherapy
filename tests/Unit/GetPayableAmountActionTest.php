<?php

use App\Actions\Transaction\GetPayableAmountAction;
use App\Models\GroupTherapy;
use App\Models\Session as TherapySession;
use App\Models\User;

// TT-7.4d-e/SCRUM-262: locks in that GetPayableAmountAction already does the right thing for
// flat-per-head group pricing (the product decision SCRUM-256 made for this whole epic) --
// deliberately just reads payment_data.amount directly with no split/recalculation logic, so
// every member pays the SAME amount regardless of group size. No code change was needed here;
// this test exists to make that intentional rather than incidental, per the ticket's own ask.

test('a PER_THERAPY GroupTherapy\'s payable amount is the flat amount, unaffected by member count', function () {
    $smallGroup = GroupTherapy::factory()->create([
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 100, 'currency' => 'GHS'],
        'max_users' => 5,
    ]);
    $largeGroup = GroupTherapy::factory()->create([
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 100, 'currency' => 'GHS'],
        'max_users' => 50,
    ]);
    $smallGroup->users()->attach(User::factory()->create()->id, ['anonymous' => false]);
    $largeGroup->users()->attach(User::factory()->count(10)->create()->pluck('id')->all(), ['anonymous' => false]);

    $smallGroupAmount = GetPayableAmountAction::new()->execute($smallGroup);
    $largeGroupAmount = GetPayableAmountAction::new()->execute($largeGroup);

    expect($smallGroupAmount['amount'])->toBe(100)
        ->and($largeGroupAmount['amount'])->toBe(100)
        ->and($smallGroupAmount)->toBe($largeGroupAmount);
});

test('a PER_SESSION GroupTherapy session\'s payable amount is the flat amount, unaffected by member count', function () {
    $groupTherapy = GroupTherapy::factory()->create([
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_SESSION', 'amount' => 50, 'currency' => 'GHS'],
        'max_users' => 20,
    ]);
    $groupTherapy->users()->attach(User::factory()->count(8)->create()->pluck('id')->all(), ['anonymous' => false]);
    $session = TherapySession::factory()->create([
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
        'payment_type' => 'PAID',
    ]);

    $result = GetPayableAmountAction::new()->execute($session);

    expect($result['amount'])->toBe(50)
        ->and($result['per'])->toBe('PER_SESSION');
});

test('an in-person PER_SESSION GroupTherapy session uses inPersonAmount, still flat regardless of member count', function () {
    $groupTherapy = GroupTherapy::factory()->create([
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_SESSION', 'amount' => 50, 'inPersonAmount' => 75, 'currency' => 'GHS'],
    ]);
    $groupTherapy->users()->attach(User::factory()->count(6)->create()->pluck('id')->all(), ['anonymous' => false]);
    $session = TherapySession::factory()->create([
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
        'payment_type' => 'PAID',
        'type' => 'IN_PERSON',
    ]);

    $result = GetPayableAmountAction::new()->execute($session);

    expect($result['amount'])->toBe(75);
});
