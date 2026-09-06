<?php

use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\OrganizationInvoiceStatusEnum;
use App\Jobs\ProcessOrganizationInvoiceSettlementJob;
use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Models\OrganizationInvoiceLine;
use App\Models\OrganizationPaymentInstrument;
use App\Models\User;
use App\Services\AppService;
use Illuminate\Support\Facades\Bus;

// TT-7.3b-e/SCRUM-236: the periodic sweep that finds every `open` invoice whose period has closed
// and settles each -- the real per-invoice work is SettleOrganizationInvoiceAction's own job; this
// only pins down the sweep's own selection criteria.

function anOrganizationInvoiceDue(array $overrides = []): OrganizationInvoice
{
    $organization = Organization::factory()->create();
    $organization->admins()->attach(User::factory()->create()->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    OrganizationPaymentInstrument::factory()->create(['organization_id' => $organization->id]);
    $invoice = OrganizationInvoice::factory()->create(array_merge([
        'organization_id' => $organization->id,
    ], $overrides));
    OrganizationInvoiceLine::factory()->create(['organization_invoice_id' => $invoice->id]);

    return $invoice;
}

beforeEach(function () {
    Bus::fake();
});

test('an open invoice whose period has closed gets settled', function () {
    $invoice = anOrganizationInvoiceDue([
        'status' => OrganizationInvoiceStatusEnum::open->value,
        'period_end' => now()->subDay()->toDateString(),
    ]);

    AppService::new()->settleDueOrganizationInvoices();

    expect($invoice->fresh()->status)->toBe(OrganizationInvoiceStatusEnum::pending->value);
    Bus::assertDispatched(ProcessOrganizationInvoiceSettlementJob::class);
});

test('an open invoice whose period has not yet closed is left alone', function () {
    $invoice = anOrganizationInvoiceDue([
        'status' => OrganizationInvoiceStatusEnum::open->value,
        'period_end' => now()->addDay()->toDateString(),
    ]);

    AppService::new()->settleDueOrganizationInvoices();

    expect($invoice->fresh()->status)->toBe(OrganizationInvoiceStatusEnum::open->value);
    Bus::assertNotDispatched(ProcessOrganizationInvoiceSettlementJob::class);
});

test('a non-open invoice past its period is never re-claimed regardless of period_end', function () {
    $invoice = anOrganizationInvoiceDue([
        'status' => OrganizationInvoiceStatusEnum::settled->value,
        'period_end' => now()->subDay()->toDateString(),
    ]);

    AppService::new()->settleDueOrganizationInvoices();

    expect($invoice->fresh()->status)->toBe(OrganizationInvoiceStatusEnum::settled->value);
    Bus::assertNotDispatched(ProcessOrganizationInvoiceSettlementJob::class);
});

// One org's inability to settle (missing payment instrument, in this case) must never block the
// sweep from reaching every other due invoice in the same run -- the whole reason for this
// sweep's own per-item try/catch, mirroring the two existing compensation-request sweeps in
// AppService.
test('one due invoice that cannot be settled does not block another due invoice in the same run', function () {
    $organizationWithNoInstrument = Organization::factory()->create();
    $organizationWithNoInstrument->admins()->attach(User::factory()->create()->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    $unsettleableInvoice = OrganizationInvoice::factory()->create([
        'organization_id' => $organizationWithNoInstrument->id,
        'status' => OrganizationInvoiceStatusEnum::open->value,
        'period_end' => now()->subDay()->toDateString(),
    ]);
    OrganizationInvoiceLine::factory()->create(['organization_invoice_id' => $unsettleableInvoice->id]);

    $settleableInvoice = anOrganizationInvoiceDue([
        'status' => OrganizationInvoiceStatusEnum::open->value,
        'period_end' => now()->subDay()->toDateString(),
    ]);

    AppService::new()->settleDueOrganizationInvoices();

    expect($unsettleableInvoice->fresh()->status)->toBe(OrganizationInvoiceStatusEnum::open->value);
    expect($settleableInvoice->fresh()->status)->toBe(OrganizationInvoiceStatusEnum::pending->value);
});
