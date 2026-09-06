<?php

use App\Actions\Organization\SettleOrganizationInvoiceAction;
use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\OrganizationInvoiceStatusEnum;
use App\Enums\TransactionStatusEnum;
use App\Jobs\ProcessOrganizationInvoiceSettlementJob;
use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Models\OrganizationInvoiceLine;
use App\Models\OrganizationPaymentInstrument;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

// TT-7.3b-e/SCRUM-236: claims an `open` invoice and starts settlement, mirroring
// TriggerCounsellorPayoutAction's own claim-then-dispatch split -- the real Paystack call happens
// in ProcessOrganizationInvoiceSettlementJob, dispatched only after this action's own
// DB::transaction() commits.

function anOpenInvoiceWithLines(array $overrides = []): array
{
    $organization = Organization::factory()->create();
    $organization->admins()->attach(User::factory()->create()->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    OrganizationPaymentInstrument::factory()->create(['organization_id' => $organization->id]);

    $invoice = OrganizationInvoice::factory()->create(array_merge([
        'organization_id' => $organization->id,
        'status' => OrganizationInvoiceStatusEnum::open->value,
    ], $overrides));

    OrganizationInvoiceLine::factory()->create([
        'organization_invoice_id' => $invoice->id,
        'net_amount' => 4500,
        'fee_amount' => 500,
        'currency' => $invoice->currency,
    ]);
    OrganizationInvoiceLine::factory()->create([
        'organization_invoice_id' => $invoice->id,
        'net_amount' => 2000,
        'fee_amount' => 300,
        'currency' => $invoice->currency,
    ]);

    return [$organization, $invoice];
}

beforeEach(function () {
    Bus::fake();
});

test('claims an open invoice, creates a pending transaction for the sum of its lines, and dispatches the settlement job', function () {
    [$organization, $invoice] = anOpenInvoiceWithLines();

    $transaction = SettleOrganizationInvoiceAction::new()->execute($invoice);

    expect($transaction)->not->toBeNull();
    expect($transaction->for_type)->toBe(OrganizationInvoice::class);
    expect($transaction->for_id)->toBe($invoice->id);
    expect($transaction->organization_id)->toBe($organization->id);
    expect($transaction->amount)->toBe(7300);
    expect($transaction->currency)->toBe($invoice->currency);
    expect($transaction->status)->toBe(TransactionStatusEnum::pending->value);

    expect($invoice->fresh()->status)->toBe(OrganizationInvoiceStatusEnum::pending->value);
    expect($invoice->fresh()->amount)->toBe(7300);

    Bus::assertDispatched(ProcessOrganizationInvoiceSettlementJob::class);
});

test('attributes the settlement transaction to the organization\'s owner-role admin', function () {
    $organization = Organization::factory()->create();
    $plainAdmin = User::factory()->create();
    $owner = User::factory()->create();
    $organization->admins()->attach($plainAdmin->id, ['role' => OrganizationAdminRoleEnum::admin->value]);
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    OrganizationPaymentInstrument::factory()->create(['organization_id' => $organization->id]);
    $invoice = OrganizationInvoice::factory()->create(['organization_id' => $organization->id, 'status' => OrganizationInvoiceStatusEnum::open->value]);
    OrganizationInvoiceLine::factory()->create(['organization_invoice_id' => $invoice->id]);

    $transaction = SettleOrganizationInvoiceAction::new()->execute($invoice);

    expect($transaction->user_id)->toBe($owner->id);
});

test('falls back to any admin when the organization somehow has no owner-role admin', function () {
    $organization = Organization::factory()->create();
    $admin = User::factory()->create();
    $organization->admins()->attach($admin->id, ['role' => OrganizationAdminRoleEnum::admin->value]);
    OrganizationPaymentInstrument::factory()->create(['organization_id' => $organization->id]);
    $invoice = OrganizationInvoice::factory()->create(['organization_id' => $organization->id, 'status' => OrganizationInvoiceStatusEnum::open->value]);
    OrganizationInvoiceLine::factory()->create(['organization_invoice_id' => $invoice->id]);

    $transaction = SettleOrganizationInvoiceAction::new()->execute($invoice);

    expect($transaction->user_id)->toBe($admin->id);
});

test('an invoice with no lines is left alone -- never settled with nothing to charge', function () {
    $organization = Organization::factory()->create();
    OrganizationPaymentInstrument::factory()->create(['organization_id' => $organization->id]);
    $invoice = OrganizationInvoice::factory()->create(['organization_id' => $organization->id, 'status' => OrganizationInvoiceStatusEnum::open->value]);

    $result = SettleOrganizationInvoiceAction::new()->execute($invoice);

    expect($result)->toBeNull();
    $this->assertDatabaseCount('transactions', 0);
    expect($invoice->fresh()->status)->toBe(OrganizationInvoiceStatusEnum::open->value);
    Bus::assertNotDispatched(ProcessOrganizationInvoiceSettlementJob::class);
});

test('an invoice that is not open (already claimed) is left alone', function () {
    [, $invoice] = anOpenInvoiceWithLines(['status' => OrganizationInvoiceStatusEnum::pending->value]);

    $result = SettleOrganizationInvoiceAction::new()->execute($invoice);

    expect($result)->toBeNull();
    $this->assertDatabaseCount('transactions', 0);
    Bus::assertNotDispatched(ProcessOrganizationInvoiceSettlementJob::class);
});

test('an organization with no payment instrument logs a warning and settles nothing', function () {
    Log::spy();
    $organization = Organization::factory()->create();
    $organization->admins()->attach(User::factory()->create()->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    $invoice = OrganizationInvoice::factory()->create(['organization_id' => $organization->id, 'status' => OrganizationInvoiceStatusEnum::open->value]);
    OrganizationInvoiceLine::factory()->create(['organization_invoice_id' => $invoice->id]);

    $result = SettleOrganizationInvoiceAction::new()->execute($invoice);

    expect($result)->toBeNull();
    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message) => str_contains($message, 'no payment instrument'))
        ->once();
});

test('an organization with no admin at all logs a warning and settles nothing', function () {
    Log::spy();
    $organization = Organization::factory()->create();
    OrganizationPaymentInstrument::factory()->create(['organization_id' => $organization->id]);
    $invoice = OrganizationInvoice::factory()->create(['organization_id' => $organization->id, 'status' => OrganizationInvoiceStatusEnum::open->value]);
    OrganizationInvoiceLine::factory()->create(['organization_invoice_id' => $invoice->id]);

    $result = SettleOrganizationInvoiceAction::new()->execute($invoice);

    expect($result)->toBeNull();
    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message) => str_contains($message, 'no admin'))
        ->once();
});

// Security-engineer finding: without this, a future caller (e.g. an admin manual-settle action,
// TT-7.3b-j) could claim a STILL-ACCRUING invoice mid-period. Since RecordOrganizationInvoiceLineForSessionAction's
// own invoice lookup doesn't filter by status, a line held after this invoice was prematurely
// claimed would silently attach to an invoice whose amount was already fixed -- generating a real
// CounsellorEarning for money that was never actually charged.
test('an invoice whose period has not yet closed is left alone, even if it somehow has lines already', function () {
    [, $invoice] = anOpenInvoiceWithLines([
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
    ]);

    $result = SettleOrganizationInvoiceAction::new()->execute($invoice);

    expect($result)->toBeNull();
    $this->assertDatabaseCount('transactions', 0);
    expect($invoice->fresh()->status)->toBe(OrganizationInvoiceStatusEnum::open->value);
    Bus::assertNotDispatched(ProcessOrganizationInvoiceSettlementJob::class);
});

test('an invoice whose period ended today (not yet fully closed) is left alone', function () {
    [, $invoice] = anOpenInvoiceWithLines([
        'period_end' => now()->toDateString(),
    ]);

    $result = SettleOrganizationInvoiceAction::new()->execute($invoice);

    expect($result)->toBeNull();
    expect($invoice->fresh()->status)->toBe(OrganizationInvoiceStatusEnum::open->value);
});

test('a payment instrument whose currency does not match the invoice currency is refused, not silently sent to Paystack', function () {
    Log::spy();
    $organization = Organization::factory()->create();
    $organization->admins()->attach(User::factory()->create()->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    OrganizationPaymentInstrument::factory()->create(['organization_id' => $organization->id, 'currency' => 'USD']);
    $invoice = OrganizationInvoice::factory()->create(['organization_id' => $organization->id, 'currency' => 'GHS', 'status' => OrganizationInvoiceStatusEnum::open->value]);
    OrganizationInvoiceLine::factory()->create(['organization_invoice_id' => $invoice->id, 'currency' => 'GHS']);

    $result = SettleOrganizationInvoiceAction::new()->execute($invoice);

    expect($result)->toBeNull();
    $this->assertDatabaseCount('transactions', 0);
    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message) => str_contains($message, 'currency does not match'))
        ->once();
});

test('a second settlement attempt after the first has already claimed the invoice finds nothing left to claim', function () {
    [, $invoice] = anOpenInvoiceWithLines();

    SettleOrganizationInvoiceAction::new()->execute($invoice);
    $result = SettleOrganizationInvoiceAction::new()->execute($invoice->fresh());

    expect($result)->toBeNull();
    $this->assertDatabaseCount('transactions', 1);
});
