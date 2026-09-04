<?php

use App\Actions\Transaction\ResolveTransactionSubjectAction;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;

// TT-7.3b-c/SCRUM-234 (reviewer finding): extracted from four previously-independent copies of
// this same ternary (TransactionController, EnsureOrganizationCanPayForModelAction,
// ChargeOrganizationForModelAction, TransactionService) -- the ONE place it happens now.

test('a Therapy resolves to itself', function () {
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);

    expect(ResolveTransactionSubjectAction::new()->execute($therapy))->toBe($therapy);
});

test('a GroupTherapy resolves to itself', function () {
    $groupTherapy = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);

    expect(ResolveTransactionSubjectAction::new()->execute($groupTherapy))->toBe($groupTherapy);
});

test('a Session belonging to a Therapy resolves to that Therapy', function () {
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);

    $resolved = ResolveTransactionSubjectAction::new()->execute($session);

    expect($resolved)->toBeInstanceOf(Therapy::class);
    expect($resolved->id)->toBe($therapy->id);
});

test('a Session belonging to a GroupTherapy resolves to that GroupTherapy', function () {
    $groupTherapy = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);
    $session = Session::factory()->create(['for_id' => $groupTherapy->id, 'for_type' => GroupTherapy::class]);

    $resolved = ResolveTransactionSubjectAction::new()->execute($session);

    expect($resolved)->toBeInstanceOf(GroupTherapy::class);
    expect($resolved->id)->toBe($groupTherapy->id);
});

test('null resolves to null', function () {
    expect(ResolveTransactionSubjectAction::new()->execute(null))->toBeNull();
});
