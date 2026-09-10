<?php

use App\Actions\Transaction\RecordRefundStatusAction;
use App\Enums\CounsellorEarningStatusEnum;
use App\Enums\RefundStatusEnum;
use App\Enums\RefundStatusSourceEnum;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\CounsellorEarning;
use App\Models\Organization;
use App\Models\PaymentAccessGrant;
use App\Models\Refund;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\RefundExecutionFailedNotification;
use App\Notifications\RefundFailedNotification;
use App\Notifications\RefundSucceededNotification;
use Illuminate\Support\Facades\Notification;

// TT-7.7d/SCRUM-252: mirrors RecordCounsellorPayoutStatusActionTest's own shape exactly -- this is
// the single choke point both ProcessRefundJob's synchronous response and the refund.processed/
// refund.failed webhook call into.

function aPendingRefundForTransaction(?Transaction $transaction = null): Refund
{
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);
    $transaction ??= Transaction::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id]);

    return Refund::factory()->create([
        'transaction_id' => $transaction->id,
        'status' => RefundStatusEnum::pending->value,
    ]);
}

test('recording success marks the refund succeeded', function () {
    $refund = aPendingRefundForTransaction();

    RecordRefundStatusAction::new()->execute(
        $refund,
        RefundStatusEnum::success->value,
        RefundStatusSourceEnum::initiate->value
    );

    expect($refund->fresh()->status)->toBe(RefundStatusEnum::success->value);
    expect($refund->fresh()->statusHistories()->count())->toBe(1);
});

// TT-7.3b-g/SCRUM-239: proves this action's own real caller reaches ReconcileOrgFinancedRefundAction
// for an org-financed transaction -- not just that the action exists in isolation.
test('recording success for an org-financed transaction triggers org reconciliation', function () {
    Notification::fake();
    $organization = Organization::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);
    $transaction = Transaction::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'organization_id' => $organization->id,
    ]);
    $earning = CounsellorEarning::factory()->create([
        'transaction_id' => $transaction->id,
        'counsellor_id' => Counsellor::factory()->create(['user_id' => User::factory()])->id,
        'status' => CounsellorEarningStatusEnum::pending->value,
    ]);
    $refund = aPendingRefundForTransaction($transaction);

    RecordRefundStatusAction::new()->execute(
        $refund,
        RefundStatusEnum::success->value,
        RefundStatusSourceEnum::initiate->value
    );

    expect($refund->fresh()->status)->toBe(RefundStatusEnum::success->value);
    // Proves the real caller reaches ReconcileOrgFinancedRefundAction (already unit-tested in
    // isolation) -- not just that this action exists. Its own reversed-earning side effect is
    // the clearest, least-setup-heavy proof the call actually happened.
    expect($earning->fresh()->status)->toBe(CounsellorEarningStatusEnum::reversed->value);
});

// Reviewer finding: reconciliation used to run AFTER this action's own DB transaction committed --
// a transient failure there could leave the refund permanently `success` with the earning still
// stranded `pending`, with no retry path. Now nested inside the SAME transaction (Laravel nests
// via savepoints), so a failure anywhere in or after reconciliation rolls the whole unit back
// together. Proven here by throwing from a model event that fires only after reconciliation has
// already mutated the earning, but still inside the outer transaction.
test('a failure occurring after reconciliation rolls back the refund status change together with it', function () {
    $organization = Organization::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);
    $transaction = Transaction::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'organization_id' => $organization->id,
    ]);
    $earning = CounsellorEarning::factory()->create([
        'transaction_id' => $transaction->id,
        'counsellor_id' => Counsellor::factory()->create(['user_id' => User::factory()])->id,
        'status' => CounsellorEarningStatusEnum::pending->value,
    ]);
    $refund = aPendingRefundForTransaction($transaction);

    CounsellorEarning::updated(function () {
        throw new RuntimeException('simulated failure after reconciliation');
    });

    expect(fn () => RecordRefundStatusAction::new()->execute(
        $refund,
        RefundStatusEnum::success->value,
        RefundStatusSourceEnum::initiate->value
    ))->toThrow(RuntimeException::class);

    expect($refund->fresh()->status)->toBe(RefundStatusEnum::pending->value);
    expect($earning->fresh()->status)->toBe(CounsellorEarningStatusEnum::pending->value);
});

test('recording success for a personally-paid transaction never touches org reconciliation', function () {
    $refund = aPendingRefundForTransaction();

    // No organization exists at all -- if ReconcileOrgFinancedRefundAction were called
    // unconditionally, its own null-organization_id guard would throw and this would fail.
    RecordRefundStatusAction::new()->execute(
        $refund,
        RefundStatusEnum::success->value,
        RefundStatusSourceEnum::initiate->value
    );

    expect($refund->fresh()->status)->toBe(RefundStatusEnum::success->value);
});

test('recording failure notifies admins of the failed refund execution', function () {
    Notification::fake();
    $admin = User::factory()->has(Administrator::factory())->create();
    $refund = aPendingRefundForTransaction();

    RecordRefundStatusAction::new()->execute(
        $refund,
        RefundStatusEnum::failed->value,
        RefundStatusSourceEnum::initiate->value,
        'Paystack could not process this refund.'
    );

    expect($refund->fresh()->status)->toBe(RefundStatusEnum::failed->value);
    Notification::assertSentTo($admin, RefundExecutionFailedNotification::class);
});

// TT-7.7e/SCRUM-253: the "outcome" notification TT-7.7d/TT-7.7c both deliberately left for this
// ticket -- the requesting client, not just admins, needs to hear the refund actually succeeded.
test('recording success notifies the requesting client of the refund outcome', function () {
    Notification::fake();
    $refund = aPendingRefundForTransaction();

    RecordRefundStatusAction::new()->execute(
        $refund,
        RefundStatusEnum::success->value,
        RefundStatusSourceEnum::initiate->value
    );

    Notification::assertSentTo($refund->requestedBy, RefundSucceededNotification::class);
    // Product-owner-mandated, mental-health trust requirement: a client must never wonder if a
    // refund means they've lost access to their counsellor -- assert the reassurance copy is
    // actually present, not just that the right notification class was sent.
    Notification::assertSentTo(
        $refund->requestedBy,
        RefundSucceededNotification::class,
        fn ($notification, $channels, $notifiable) => str_contains(
            $notification->toMail($notifiable)->render(),
            'Your access to the platform and your therapy is completely unaffected'
        )
    );
});

test('recording failure notifies the requesting client of the refund outcome, distinct from the admin notification', function () {
    Notification::fake();
    $admin = User::factory()->has(Administrator::factory())->create();
    $refund = aPendingRefundForTransaction();

    RecordRefundStatusAction::new()->execute(
        $refund,
        RefundStatusEnum::failed->value,
        RefundStatusSourceEnum::initiate->value,
        'Paystack could not process this refund.'
    );

    Notification::assertSentTo($refund->requestedBy, RefundFailedNotification::class);
    Notification::assertSentTo($admin, RefundExecutionFailedNotification::class);
    Notification::assertNotSentTo($refund->requestedBy, RefundExecutionFailedNotification::class);
    // Same reassurance requirement as the success notification above.
    Notification::assertSentTo(
        $refund->requestedBy,
        RefundFailedNotification::class,
        fn ($notification, $channels, $notifiable) => str_contains(
            $notification->toMail($notifiable)->render(),
            'Your access to the platform and your therapy is completely unaffected'
        )
    );
});

test('a terminal refund status is never regressed by a later, differently-statused event', function () {
    Notification::fake();
    $refund = aPendingRefundForTransaction();
    $refund->update(['status' => RefundStatusEnum::success->value]);

    RecordRefundStatusAction::new()->execute(
        $refund,
        RefundStatusEnum::failed->value,
        RefundStatusSourceEnum::webhook->value,
        'a stale refund.failed event'
    );

    expect($refund->fresh()->status)->toBe(RefundStatusEnum::success->value);
    expect($refund->fresh()->statusHistories()->count())->toBe(0);
    Notification::assertNothingSent();
});

test('recording the same status twice is idempotent -- no duplicate history, no duplicate notification', function () {
    Notification::fake();
    $admin = User::factory()->has(Administrator::factory())->create();
    $refund = aPendingRefundForTransaction();

    RecordRefundStatusAction::new()->execute($refund, RefundStatusEnum::failed->value, RefundStatusSourceEnum::initiate->value);
    RecordRefundStatusAction::new()->execute($refund->fresh(), RefundStatusEnum::failed->value, RefundStatusSourceEnum::initiate->value);

    expect($refund->fresh()->statusHistories()->count())->toBe(1);
    Notification::assertSentToTimes($admin, RefundExecutionFailedNotification::class, 1);
    Notification::assertSentToTimes($refund->requestedBy, RefundFailedNotification::class, 1);
});

// Security-engineer finding: seeding the grant for a brand-new, unrelated Therapy/User (as this
// test originally did) would pass identically even if this isolation guarantee were entirely
// absent -- nothing in this code path would ever have touched that unrelated row anyway. Tying
// the grant to the SAME transaction/therapy the refund under test actually belongs to makes this
// a meaningful regression test.
test('never reads or writes payment_access_grants', function () {
    $user = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $user->id]);
    $transaction = Transaction::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id]);
    $grant = PaymentAccessGrant::create([
        'user_id' => $user->id,
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'transaction_id' => $transaction->id,
        'granted_at' => now(),
    ]);
    $refund = aPendingRefundForTransaction($transaction);

    RecordRefundStatusAction::new()->execute(
        $refund,
        RefundStatusEnum::success->value,
        RefundStatusSourceEnum::initiate->value
    );

    $this->assertDatabaseCount('payment_access_grants', 1);
    expect($grant->fresh()->granted_at->equalTo($grant->granted_at))->toBeTrue();
});
