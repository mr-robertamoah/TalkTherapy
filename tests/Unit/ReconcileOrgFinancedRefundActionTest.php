<?php

use App\Actions\Transaction\ReconcileOrgFinancedRefundAction;
use App\Enums\CounsellorEarningStatusEnum;
use App\Enums\OrganizationAdminRoleEnum;
use App\Exceptions\TransactionException;
use App\Models\Counsellor;
use App\Models\CounsellorEarning;
use App\Models\GroupTherapy;
use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Models\OrganizationInvoiceLine;
use App\Models\PaymentAccessGrant;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\OrganizationFinancedTransactionRefundedNotification;
use Illuminate\Support\Facades\Notification;

// TT-7.3b-g/SCRUM-239: callable-only refund-reconciliation hook -- independently testable via
// direct invocation today, per the ticket's own explicit scope (TT-7.7d, which will call this,
// doesn't exist yet).

function anOrgFinancedTransactionWithEarning(string $earningStatus = 'PENDING'): array
{
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory(), 'counsellor_id' => $counsellor->id]);
    $transaction = Transaction::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'organization_id' => $organization->id,
        'status' => 'SUCCESS',
    ]);
    $earning = CounsellorEarning::factory()->create([
        'transaction_id' => $transaction->id,
        'counsellor_id' => $counsellor->id,
        'status' => $earningStatus,
    ]);

    return [$transaction, $earning, $organization, $owner];
}

beforeEach(function () {
    Notification::fake();
});

test('reverses a pending earning and notifies the organization\'s admins', function () {
    [$transaction, $earning, , $owner] = anOrgFinancedTransactionWithEarning(CounsellorEarningStatusEnum::pending->value);

    ReconcileOrgFinancedRefundAction::new()->execute($transaction);

    expect($earning->fresh()->status)->toBe(CounsellorEarningStatusEnum::reversed->value);
    expect($earning->fresh()->statusHistories()->count())->toBe(1);
    Notification::assertSentTo($owner, OrganizationFinancedTransactionRefundedNotification::class, function ($notification) use ($owner) {
        return $notification->toArray($owner)['needsManualReview'] === false;
    });
});

// Reviewer + security-engineer finding (both independently, HIGH severity): a PROCESSING earning
// is already claimed by a specific CounsellorPayout whose own later resolution
// (RecordCounsellorPayoutStatusAction) does a blanket, status-agnostic update -- reversing it here
// would be silently clobbered back to PAID_OUT or PENDING once that payout resolves, with no
// trace the reversal ever happened. It must be flagged for manual follow-up instead, exactly like
// an already-paid-out earning, never auto-mutated.
test('never reverses a processing (in-flight payout) earning -- flags it for manual reconciliation instead', function () {
    [$transaction, $earning, , $owner] = anOrgFinancedTransactionWithEarning(CounsellorEarningStatusEnum::processing->value);

    ReconcileOrgFinancedRefundAction::new()->execute($transaction);

    expect($earning->fresh()->status)->toBe(CounsellorEarningStatusEnum::processing->value);
    Notification::assertSentTo($owner, OrganizationFinancedTransactionRefundedNotification::class, function ($notification) use ($owner) {
        return $notification->toArray($owner)['needsManualReview'] === true;
    });
});

// Security/correctness-critical: money that's already left the platform must never be silently
// "un-reversed" by this action -- it needs manual follow-up, not an automatic status flip.
test('never reverses an already-paid-out earning -- flags it for manual reconciliation instead', function () {
    [$transaction, $earning, , $owner] = anOrgFinancedTransactionWithEarning(CounsellorEarningStatusEnum::paidOut->value);

    ReconcileOrgFinancedRefundAction::new()->execute($transaction);

    expect($earning->fresh()->status)->toBe(CounsellorEarningStatusEnum::paidOut->value);
    Notification::assertSentTo($owner, OrganizationFinancedTransactionRefundedNotification::class, function ($notification) use ($owner) {
        return $notification->toArray($owner)['needsManualReview'] === true;
    });
});

test('an already-reversed or failed earning is left alone -- a repeated call finds nothing left to reverse', function () {
    [$transaction, $earning] = anOrgFinancedTransactionWithEarning(CounsellorEarningStatusEnum::pending->value);

    ReconcileOrgFinancedRefundAction::new()->execute($transaction);
    ReconcileOrgFinancedRefundAction::new()->execute($transaction->fresh());

    expect($earning->fresh()->status)->toBe(CounsellorEarningStatusEnum::reversed->value);
    expect($earning->fresh()->statusHistories()->count())->toBe(1);
});

// Generic across both org-billing modes -- a retainer SETTLEMENT transaction fans out into
// multiple earnings (one per invoice line), and every one of them must be reversed, not just the
// first.
test('reverses every earning tied to a retainer settlement transaction, not just one', function () {
    $organization = Organization::factory()->create();
    $organization->admins()->attach(User::factory()->create()->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    $invoice = OrganizationInvoice::factory()->create(['organization_id' => $organization->id]);
    $lineOne = OrganizationInvoiceLine::factory()->create(['organization_invoice_id' => $invoice->id]);
    $lineTwo = OrganizationInvoiceLine::factory()->create(['organization_invoice_id' => $invoice->id]);
    $transaction = Transaction::factory()->create([
        'for_type' => OrganizationInvoice::class,
        'for_id' => $invoice->id,
        'organization_id' => $organization->id,
        'status' => 'SUCCESS',
    ]);
    $earningOne = CounsellorEarning::factory()->create([
        'transaction_id' => $transaction->id,
        'counsellor_id' => Counsellor::factory()->create(['user_id' => User::factory()]),
        'organization_invoice_line_id' => $lineOne->id,
        'status' => CounsellorEarningStatusEnum::pending->value,
    ]);
    $earningTwo = CounsellorEarning::factory()->create([
        'transaction_id' => $transaction->id,
        'counsellor_id' => Counsellor::factory()->create(['user_id' => User::factory()]),
        'organization_invoice_line_id' => $lineTwo->id,
        'status' => CounsellorEarningStatusEnum::pending->value,
    ]);

    ReconcileOrgFinancedRefundAction::new()->execute($transaction);

    expect($earningOne->fresh()->status)->toBe(CounsellorEarningStatusEnum::reversed->value);
    expect($earningTwo->fresh()->status)->toBe(CounsellorEarningStatusEnum::reversed->value);
});

test('a non-org-financed transaction cannot be reconciled', function () {
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);
    $transaction = Transaction::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id, 'organization_id' => null]);

    ReconcileOrgFinancedRefundAction::new()->execute($transaction);
})->throws(TransactionException::class);

test('an organization with no admins logs a warning rather than throwing', function () {
    $organization = Organization::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory(), 'counsellor_id' => $counsellor->id]);
    $transaction = Transaction::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id, 'organization_id' => $organization->id]);
    CounsellorEarning::factory()->create(['transaction_id' => $transaction->id, 'counsellor_id' => $counsellor->id, 'status' => CounsellorEarningStatusEnum::pending->value]);

    expect(fn () => ReconcileOrgFinancedRefundAction::new()->execute($transaction))->not->toThrow(Throwable::class);

    Notification::assertNothingSent();
});

// Carried forward from TT-7.3b-b/-c's own scope boundary: GroupTherapy org billing was never
// built, so an org-financed GroupTherapy transaction has zero earnings to reverse -- this must
// still be a true no-op that notifies the org's admins, not an error.
test('an org-financed GroupTherapy transaction (no earnings at all) is a true no-op that still notifies', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    $groupTherapy = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);
    $transaction = Transaction::factory()->create([
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
        'organization_id' => $organization->id,
    ]);

    ReconcileOrgFinancedRefundAction::new()->execute($transaction);

    Notification::assertSentTo($owner, OrganizationFinancedTransactionRefundedNotification::class, function ($notification) use ($owner) {
        return $notification->toArray($owner)['needsManualReview'] === false;
    });
});

// Reviewer + security-engineer finding (both independently): Organization uses SoftDeletes, and
// Transaction::organization() is a plain belongsTo -- Eloquent's default query excludes trashed
// rows, which would otherwise crash this action with "call to a member function admins() on
// null" for a refund reconciled after the financing org was deactivated. Fixed with
// ->withTrashed() (mirrors OrganizationInvoiceLine::counsellor()'s own identical precedent) --
// a soft-deleted org is still resolvable, so reconciliation proceeds completely normally: the
// admin accounts still exist and still need to know about the refund for their own records,
// deactivation is not the same as gone.
test('a soft-deleted financing organization does not crash -- reconciliation proceeds normally', function () {
    [$transaction, $earning, $organization, $owner] = anOrgFinancedTransactionWithEarning(CounsellorEarningStatusEnum::pending->value);
    $organization->delete();

    expect(fn () => ReconcileOrgFinancedRefundAction::new()->execute($transaction))->not->toThrow(Throwable::class);

    expect($earning->fresh()->status)->toBe(CounsellorEarningStatusEnum::reversed->value);
    Notification::assertSentTo($owner, OrganizationFinancedTransactionRefundedNotification::class);
});

// Explicit regression test carried from TT-7.7's own product-owner mandate (this ticket's own
// scope text repeats it verbatim).
test('never reads or writes payment_access_grants', function () {
    [$transaction, , , $owner] = anOrgFinancedTransactionWithEarning(CounsellorEarningStatusEnum::pending->value);
    $existingGrant = PaymentAccessGrant::create([
        'user_id' => $owner->id,
        'for_type' => Therapy::class,
        'for_id' => $transaction->for_id,
        'granted_at' => now(),
    ]);

    ReconcileOrgFinancedRefundAction::new()->execute($transaction);

    $this->assertDatabaseCount('payment_access_grants', 1);
    expect($existingGrant->fresh()->granted_at->equalTo($existingGrant->granted_at))->toBeTrue();
});
