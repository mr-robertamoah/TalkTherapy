<?php

use App\Actions\Transaction\ChargeOrganizationForModelAction;
use App\Actions\Transaction\RecordRefundStatusAction;
use App\Actions\Transaction\RequestRefundAction;
use App\Enums\CounsellorEarningStatusEnum;
use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\RefundStatusEnum;
use App\Enums\RefundStatusSourceEnum;
use App\Enums\TransactionStatusEnum;
use App\Exceptions\TransactionException;
use App\Models\Counsellor;
use App\Models\CounsellorEarning;
use App\Models\GroupTherapy;
use App\Models\Organization;
use App\Models\Refund;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\OrganizationFinancedTransactionRefundedNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

// SCRUM-420 part 3: a member's place in a group, paid by the organisation's card, is refunded the way a one-to-one
// organisation charge is -- and every counsellor's earning from it is reversed, not just one.

// A successful organisation card charge for $member's place, with one pending earning per counsellor. Returns
// [transaction, earnings, organisation owner].
function gorGroupCharge(User $member, GroupTherapy $group, int $counsellors = 2): array
{
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

    $transaction = Transaction::factory()->create([
        'for_type' => GroupTherapy::class, 'for_id' => $group->id, 'user_id' => $member->id,
        'organization_id' => $organization->id, 'reference' => ChargeOrganizationForModelAction::REFERENCE_PREFIX.Str::uuid(),
        'amount' => 3000 * $counsellors, 'status' => TransactionStatusEnum::success->value,
    ]);

    $earnings = collect(range(1, $counsellors))->map(fn () => CounsellorEarning::factory()->create([
        'transaction_id' => $transaction->id,
        'counsellor_id' => Counsellor::factory()->create(['user_id' => User::factory()])->id,
        'net_amount' => 2500, 'fee_amount' => 500, 'status' => CounsellorEarningStatusEnum::pending->value,
    ]));

    return [$transaction, $earnings, $owner];
}

function gorRefundSucceeds(Transaction $transaction): void
{
    $refund = Refund::factory()->create(['transaction_id' => $transaction->id, 'status' => RefundStatusEnum::pending->value]);

    RecordRefundStatusAction::new()->execute($refund, RefundStatusEnum::success->value, RefundStatusSourceEnum::initiate->value);
}

beforeEach(function () {
    Notification::fake();
});

test('refunding a member\'s group place reverses every counsellor\'s earning from it and tells the organisation', function () {
    $member = User::factory()->adult()->create();
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $member->id]);
    [$transaction, $earnings, $owner] = gorGroupCharge($member, $group);

    gorRefundSucceeds($transaction);

    expect($earnings->map(fn ($earning) => $earning->fresh()->status)->unique()->all())->toBe([CounsellorEarningStatusEnum::reversed->value]);
    Notification::assertSentTo($owner, OrganizationFinancedTransactionRefundedNotification::class,
        fn ($notification) => $notification->toArray($owner)['needsManualReview'] === false);
});

test('one counsellor already paid out is flagged; the others are still reversed', function () {
    $member = User::factory()->adult()->create();
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $member->id]);
    [$transaction, $earnings, $owner] = gorGroupCharge($member, $group);
    $earnings[0]->update(['status' => CounsellorEarningStatusEnum::paidOut->value]);

    gorRefundSucceeds($transaction);

    expect($earnings[0]->fresh()->status)->toBe(CounsellorEarningStatusEnum::paidOut->value)
        ->and($earnings[1]->fresh()->status)->toBe(CounsellorEarningStatusEnum::reversed->value);
    Notification::assertSentTo($owner, OrganizationFinancedTransactionRefundedNotification::class,
        fn ($notification) => $notification->toArray($owner)['needsManualReview'] === true);
});

test('another member\'s place in the same group is untouched by the refund', function () {
    [$member, $other] = [User::factory()->adult()->create(), User::factory()->adult()->create()];
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $member->id]);
    [$transaction] = gorGroupCharge($member, $group);
    [, $otherEarnings] = gorGroupCharge($other, $group);

    gorRefundSucceeds($transaction);

    expect($otherEarnings->map(fn ($earning) => $earning->fresh()->status)->unique()->all())->toBe([CounsellorEarningStatusEnum::pending->value]);
});

test('only the member whose place it was can ask for its refund', function () {
    [$member, $other] = [User::factory()->adult()->create(), User::factory()->adult()->create()];
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $member->id]);
    [$transaction] = gorGroupCharge($member, $group);

    expect(fn () => RequestRefundAction::new()->execute($other, $transaction, 'Not mine'))
        ->toThrow(TransactionException::class, 'You are not authorized to request a refund for this transaction.');
    expect(RequestRefundAction::new()->execute($member, $transaction, 'Could not attend')->exists)->toBeTrue();
});
