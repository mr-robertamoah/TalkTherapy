<?php

use App\Actions\GroupTherapy\GetGroupTherapyPaymentRosterAction;
use App\Enums\ConstantsEnum;
use App\Enums\TransactionStatusEnum;
use App\Models\GroupTherapy;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// TT-7.4d-d/SCRUM-261: the counsellor-facing per-member payment roster.

test('the roster lists every member with their own latest transaction status', function () {
    $groupTherapy = GroupTherapy::factory()->create();
    $memberA = User::factory()->create();
    $memberB = User::factory()->create();
    $memberC = User::factory()->create();
    $groupTherapy->users()->attach([$memberA->id, $memberB->id, $memberC->id], ['anonymous' => false]);

    Transaction::factory()->create([
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
        'user_id' => $memberA->id,
        'status' => TransactionStatusEnum::success->value,
    ]);
    Transaction::factory()->create([
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
        'user_id' => $memberB->id,
        'status' => TransactionStatusEnum::failed->value,
    ]);
    // memberC never paid -- must appear with a null status, not be omitted from the roster.

    $roster = GetGroupTherapyPaymentRosterAction::new()->execute($groupTherapy);

    expect($roster)->toHaveCount(3);

    $byId = collect($roster)->keyBy('id');
    expect($byId[$memberA->id]['paymentStatus'])->toBe(TransactionStatusEnum::success->value)
        ->and($byId[$memberB->id]['paymentStatus'])->toBe(TransactionStatusEnum::failed->value)
        ->and($byId[$memberC->id]['paymentStatus'])->toBeNull()
        ->and($byId[$memberC->id]['transactionId'])->toBeNull();
});

// The whole point of this action, per the architect's own mandatory finding: a per-member loop
// calling latestTransactionFor() would issue one query per member -- this must stay flat at
// exactly 2 queries (users, transactions) regardless of how many members the group has.
test('the roster resolves in a fixed number of queries regardless of member count', function () {
    $groupTherapy = GroupTherapy::factory()->create();
    $members = User::factory()->count(8)->create();
    $groupTherapy->users()->attach($members->pluck('id')->all(), ['anonymous' => false]);

    foreach ($members->take(4) as $member) {
        Transaction::factory()->create([
            'for_type' => GroupTherapy::class,
            'for_id' => $groupTherapy->id,
            'user_id' => $member->id,
            'status' => TransactionStatusEnum::success->value,
        ]);
    }

    DB::enableQueryLog();
    GetGroupTherapyPaymentRosterAction::new()->execute($groupTherapy);
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queryCount)->toBe(2);
});

// latestOfMany-equivalent behavior: a member with more than one transaction must show their MOST
// RECENT one, not an arbitrary or oldest one.
test('a member with multiple transactions shows the most recently created one', function () {
    $groupTherapy = GroupTherapy::factory()->create();
    $member = User::factory()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false]);

    Transaction::factory()->create([
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
        'user_id' => $member->id,
        'status' => TransactionStatusEnum::failed->value,
        'created_at' => now()->subDay(),
    ]);
    $latest = Transaction::factory()->create([
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
        'user_id' => $member->id,
        'status' => TransactionStatusEnum::success->value,
        'created_at' => now(),
    ]);

    $roster = GetGroupTherapyPaymentRosterAction::new()->execute($groupTherapy);

    expect($roster[0]['paymentStatus'])->toBe(TransactionStatusEnum::success->value)
        ->and($roster[0]['transactionId'])->toBe($latest->id);
});

// Security review finding (user-confirmed): a member's own per-member anonymity opt-in
// (group_therapy_user.anonymous) is independent of the group's own `anonymous` flag, and this
// roster's approved exception only covers the latter -- deliberately NOT
// GroupTherapy::isAnonymousFor(), which ORs in the group-level flag too and would mask everyone.
test('a member who individually opted into anonymity is masked in the roster, even though their payment status still shows', function () {
    $groupTherapy = GroupTherapy::factory()->create(['anonymous' => false]);
    $anonymousMember = User::factory()->create();
    $regularMember = User::factory()->create();
    $groupTherapy->users()->attach($anonymousMember->id, ['anonymous' => true]);
    $groupTherapy->users()->attach($regularMember->id, ['anonymous' => false]);
    Transaction::factory()->create([
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
        'user_id' => $anonymousMember->id,
        'status' => TransactionStatusEnum::success->value,
    ]);

    $roster = GetGroupTherapyPaymentRosterAction::new()->execute($groupTherapy);
    $byId = collect($roster)->keyBy('id');

    expect($byId[$anonymousMember->id]['fullName'])->toBe(ConstantsEnum::anonymousUserLabel->value)
        ->and($byId[$anonymousMember->id]['username'])->toBeNull()
        // Payment status is never masked -- the whole point of the roster is reconciliation,
        // which must stay meaningful even for an anonymous member.
        ->and($byId[$anonymousMember->id]['paymentStatus'])->toBe(TransactionStatusEnum::success->value)
        ->and($byId[$regularMember->id]['fullName'])->toBe($regularMember->name)
        ->and($byId[$regularMember->id]['username'])->toBe($regularMember->username);
});
