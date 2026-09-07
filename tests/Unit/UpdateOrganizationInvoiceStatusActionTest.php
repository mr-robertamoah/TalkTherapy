<?php

use App\Actions\Organization\UpdateOrganizationInvoiceStatusAction;
use App\Enums\OrganizationInvoiceStatusEnum;
use App\Enums\TransactionStatusEnum;
use App\Models\Administrator;
use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\OrganizationBillingSuspensionMayBeResolvedNotification;
use Illuminate\Support\Facades\Notification;

// TT-7.3b-e/SCRUM-236 + TT-7.3b-f2/SCRUM-238: direct unit coverage of this action's own contract,
// independent of RecordTransactionStatusAction's own wiring (see ProcessOrganizationInvoiceSettlementJobTest
// for the same behavior exercised through the real settlement job end to end).

function anOrganizationInvoiceTransaction(string $status = TransactionStatusEnum::pending->value): array
{
    $organization = Organization::factory()->create();
    $invoice = OrganizationInvoice::factory()->create([
        'organization_id' => $organization->id,
        'status' => OrganizationInvoiceStatusEnum::pending->value,
    ]);
    $transaction = Transaction::factory()->create([
        'for_type' => OrganizationInvoice::class,
        'for_id' => $invoice->id,
        'organization_id' => $organization->id,
        'status' => $status,
    ]);

    return [$transaction, $invoice, $organization];
}

test('a success status settles the invoice and does not suspend the organization', function () {
    [$transaction, $invoice, $organization] = anOrganizationInvoiceTransaction();

    UpdateOrganizationInvoiceStatusAction::new()->execute($transaction, TransactionStatusEnum::success->value);

    expect($invoice->fresh()->status)->toBe(OrganizationInvoiceStatusEnum::settled->value);
    expect($organization->fresh()->isBillingSuspended())->toBeFalse();
});

test('a failed status fails the invoice AND suspends the organization\'s billing', function () {
    [$transaction, $invoice, $organization] = anOrganizationInvoiceTransaction();

    UpdateOrganizationInvoiceStatusAction::new()->execute($transaction, TransactionStatusEnum::failed->value);

    expect($invoice->fresh()->status)->toBe(OrganizationInvoiceStatusEnum::failed->value);
    $organization = $organization->fresh();
    expect($organization->isBillingSuspended())->toBeTrue();
    expect($organization->billing_suspension_reason)->toContain('Retainer invoice settlement failed');
});

test('an already-suspended organization gets its suspension timestamp refreshed on a further failure', function () {
    [$transaction, , $organization] = anOrganizationInvoiceTransaction();
    $organization->suspendBilling('An earlier failure.');
    $firstSuspendedAt = $organization->billing_suspended_at;

    UpdateOrganizationInvoiceStatusAction::new()->execute($transaction, TransactionStatusEnum::failed->value);

    expect($organization->fresh()->billing_suspended_at->greaterThanOrEqualTo($firstSuspendedAt))->toBeTrue();
});

test('a pending or abandoned status is a no-op -- neither settles/fails the invoice nor suspends the organization', function () {
    [$transaction, $invoice, $organization] = anOrganizationInvoiceTransaction();

    UpdateOrganizationInvoiceStatusAction::new()->execute($transaction, TransactionStatusEnum::abandoned->value);

    expect($invoice->fresh()->status)->toBe(OrganizationInvoiceStatusEnum::pending->value);
    expect($organization->fresh()->isBillingSuspended())->toBeFalse();
});

// TT-7.3b-followup/SCRUM-245: a settlement succeeding while the org is ALREADY suspended (the
// only way that happens today is via RetryOrganizationInvoiceSettlementAction, since a fresh
// `open` invoice's own org is never suspended by definition -- suspension only exists once a
// PRIOR invoice already failed) notifies staff, but never auto-lifts the suspension itself.
test('a success status notifies 2 random admins when the organization is currently billing-suspended', function () {
    Notification::fake();
    [$transaction, $invoice, $organization] = anOrganizationInvoiceTransaction();
    $organization->suspendBilling('Retainer invoice settlement failed for an earlier period.');
    User::factory()->count(3)->has(Administrator::factory())->create();

    UpdateOrganizationInvoiceStatusAction::new()->execute($transaction, TransactionStatusEnum::success->value);

    expect($invoice->fresh()->status)->toBe(OrganizationInvoiceStatusEnum::settled->value);
    // Still suspended -- this action is not the one that ever lifts it.
    expect($organization->fresh()->isBillingSuspended())->toBeTrue();
    // Exactly 2 of the 3 admins, per whereAdmin()->inRandomOrder()->limit(2) -- which 2 is
    // non-deterministic by design, so only the count (not identity) is pinned here.
    Notification::assertSentTimes(OrganizationBillingSuspensionMayBeResolvedNotification::class, 2);
});

test('a success status sends no notification when the organization is not billing-suspended', function () {
    Notification::fake();
    [$transaction] = anOrganizationInvoiceTransaction();
    User::factory()->has(Administrator::factory())->create();

    UpdateOrganizationInvoiceStatusAction::new()->execute($transaction, TransactionStatusEnum::success->value);

    Notification::assertNothingSent();
});

test('a transaction whose subject is not an OrganizationInvoice is untouched', function () {
    $organization = Organization::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);
    $transaction = Transaction::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'organization_id' => $organization->id,
        'status' => TransactionStatusEnum::pending->value,
    ]);

    UpdateOrganizationInvoiceStatusAction::new()->execute($transaction, TransactionStatusEnum::failed->value);

    expect($organization->fresh()->isBillingSuspended())->toBeFalse();
});
