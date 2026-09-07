<?php

use App\Actions\Organization\RetryOrganizationInvoiceSettlementAction;
use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\OrganizationInvoiceStatusEnum;
use App\Enums\TransactionStatusEnum;
use App\Exceptions\OrganizationException;
use App\Jobs\ProcessOrganizationInvoiceSettlementJob;
use App\Models\Administrator;
use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Models\OrganizationInvoiceLine;
use App\Models\OrganizationPaymentInstrument;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

// TT-7.3b-followup/SCRUM-245: admin-triggered re-attempt of a `failed` retainer settlement --
// SCRUM-236 shipped with no retry mechanism at all, so this is the first way one can ever be
// retried. Deliberately thin over SettleOrganizationInvoiceAction (see that file's own comment).

function aFailedInvoiceWithLines(): array
{
    $organization = Organization::factory()->create();
    $organization->admins()->attach(User::factory()->create()->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    OrganizationPaymentInstrument::factory()->create(['organization_id' => $organization->id]);
    $organization->suspendBilling('Retainer invoice settlement failed.');

    $invoice = OrganizationInvoice::factory()->create([
        'organization_id' => $organization->id,
        'status' => OrganizationInvoiceStatusEnum::failed->value,
    ]);

    OrganizationInvoiceLine::factory()->create([
        'organization_invoice_id' => $invoice->id,
        'net_amount' => 4500,
        'fee_amount' => 500,
        'currency' => $invoice->currency,
    ]);

    return [$organization, $invoice];
}

beforeEach(function () {
    Bus::fake();
});

test('a platform admin can retry a failed invoice\'s settlement', function () {
    [, $invoice] = aFailedInvoiceWithLines();
    $admin = User::factory()->has(Administrator::factory())->create();

    $transaction = RetryOrganizationInvoiceSettlementAction::new()->execute($admin, $invoice);

    expect($transaction)->not->toBeNull();
    expect($transaction->status)->toBe(TransactionStatusEnum::pending->value);
    expect($invoice->fresh()->status)->toBe(OrganizationInvoiceStatusEnum::pending->value);
    Bus::assertDispatched(ProcessOrganizationInvoiceSettlementJob::class);
});

test('a non-admin cannot retry a settlement', function () {
    [, $invoice] = aFailedInvoiceWithLines();
    $plainUser = User::factory()->create();

    RetryOrganizationInvoiceSettlementAction::new()->execute($plainUser, $invoice);
})->throws(OrganizationException::class);

test('an invoice that is not failed cannot be retried', function () {
    $organization = Organization::factory()->create();
    $invoice = OrganizationInvoice::factory()->create([
        'organization_id' => $organization->id,
        'status' => OrganizationInvoiceStatusEnum::open->value,
    ]);
    $admin = User::factory()->has(Administrator::factory())->create();

    RetryOrganizationInvoiceSettlementAction::new()->execute($admin, $invoice);
})->throws(OrganizationException::class, 'Only a failed invoice settlement can be retried.');

test('a nonexistent invoice throws a clean error, not a crash', function () {
    $admin = User::factory()->has(Administrator::factory())->create();

    RetryOrganizationInvoiceSettlementAction::new()->execute($admin, null);
})->throws(OrganizationException::class, 'Organization invoice not found.');
