<?php

use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\OrganizationInvoiceStatusEnum;
use App\Jobs\ProcessOrganizationInvoiceSettlementJob;
use App\Models\Administrator;
use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Models\OrganizationInvoiceLine;
use App\Models\OrganizationPaymentInstrument;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

// TT-7.3b-followup/SCRUM-245: the manual "resolve this" surface SCRUM-238 deliberately left
// unbuilt -- platform-admin only, mirrors AdminPayoutController/PayoutController's own coverage
// shape.

test('a platform admin can load the organization-billing page', function () {
    $admin = User::factory()->has(Administrator::factory())->create();
    $organization = Organization::factory()->create();
    $organization->suspendBilling('Retainer invoice settlement failed.');
    OrganizationInvoice::factory()->create([
        'organization_id' => $organization->id,
        'status' => OrganizationInvoiceStatusEnum::failed->value,
    ]);

    $this->actingAs($admin);

    $response = $this->get(route('administrator.organization_billing'));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->component('Admin/OrganizationBilling')
        ->has('organizations.data', 1)
        ->where('organizations.data.0.id', $organization->id)
        ->where('organizations.data.0.hasPaymentInstrument', false)
    );
});

test('a non-admin is redirected home rather than seeing the organization-billing page', function () {
    $outsider = User::factory()->create();

    $this->actingAs($outsider);

    $response = $this->get(route('administrator.organization_billing'));

    $response->assertRedirect(route('home'));
});

test('an unsuspended organization does not appear in the list', function () {
    $admin = User::factory()->has(Administrator::factory())->create();
    Organization::factory()->create();

    $this->actingAs($admin);

    $response = $this->get(route('administrator.organization_billing'));

    $response->assertInertia(fn ($page) => $page->has('organizations.data', 0));
});

test('a platform admin can retry a failed invoice\'s settlement via the real HTTP endpoint', function () {
    Bus::fake();
    $admin = User::factory()->has(Administrator::factory())->create();
    $organization = Organization::factory()->create();
    $organization->admins()->attach(User::factory()->create()->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    OrganizationPaymentInstrument::factory()->create(['organization_id' => $organization->id]);
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

    $this->actingAs($admin);

    $response = $this->post(route('admin.organization_invoices.retry_settlement', ['organizationInvoiceId' => $invoice->id]));

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();
    expect($invoice->fresh()->status)->toBe(OrganizationInvoiceStatusEnum::pending->value);
    Bus::assertDispatched(ProcessOrganizationInvoiceSettlementJob::class);
});

test('a non-admin cannot retry a settlement via the real HTTP endpoint', function () {
    $outsider = User::factory()->create();
    $organization = Organization::factory()->create();
    $invoice = OrganizationInvoice::factory()->create([
        'organization_id' => $organization->id,
        'status' => OrganizationInvoiceStatusEnum::failed->value,
    ]);

    $this->actingAs($outsider);

    $response = $this->post(route('admin.organization_invoices.retry_settlement', ['organizationInvoiceId' => $invoice->id]));

    $response->assertSessionHasErrors('alert');
    expect($invoice->fresh()->status)->toBe(OrganizationInvoiceStatusEnum::failed->value);
});

test('a platform admin can lift a billing suspension via the real HTTP endpoint', function () {
    $admin = User::factory()->has(Administrator::factory())->create();
    $organization = Organization::factory()->create();
    $organization->suspendBilling('Retainer invoice settlement failed.');

    $this->actingAs($admin);

    $response = $this->post(route('admin.organizations.lift_billing_suspension', ['organizationId' => $organization->id]));

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();
    expect($organization->fresh()->isBillingSuspended())->toBeFalse();
});

test('a non-admin cannot lift a billing suspension via the real HTTP endpoint', function () {
    $outsider = User::factory()->create();
    $organization = Organization::factory()->create();
    $organization->suspendBilling('Retainer invoice settlement failed.');

    $this->actingAs($outsider);

    $response = $this->post(route('admin.organizations.lift_billing_suspension', ['organizationId' => $organization->id]));

    $response->assertSessionHasErrors('alert');
    expect($organization->fresh()->isBillingSuspended())->toBeTrue();
});

test('a guest is redirected to login', function () {
    $response = $this->get(route('administrator.organization_billing'));

    $response->assertRedirect(route('login'));
});
