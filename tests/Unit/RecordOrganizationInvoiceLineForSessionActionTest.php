<?php

use App\Actions\Organization\RecordOrganizationInvoiceLineForSessionAction;
use App\Enums\OrganizationCounsellorCompensationBasisEnum;
use App\Enums\OrganizationCounsellorCompensationTypeEnum;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Enums\OrganizationInvoiceStatusEnum;
use App\Enums\OrganizationMemberBillingModeEnum;
use App\Models\Counsellor;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\OrganizationCounsellorCompensation;
use App\Models\OrganizationInvoice;
use App\Models\OrganizationInvoiceLine;
use App\Models\OrganizationMember;
use App\Models\OrganizationMemberBillingConfig;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Support\Facades\Log;

// TT-7.3b-e/SCRUM-236: the ONE place a retainer-covered session's billable value gets computed
// AND PERSISTED, at held-time -- see this action's own header comment for the full architect
// reasoning on why this is locked in here rather than recomputed at settlement.

function aRetainerCoveredSession(array $compensationOverrides = [], array $therapyOverrides = []): array
{
    $organization = Organization::factory()->create(['is_consumer' => true, 'verified_at' => now()]);

    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);

    $affiliation = OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellor->id,
        'status' => OrganizationCounsellorStatusEnum::active->value,
    ]);

    OrganizationCounsellorCompensation::factory()->create(array_merge([
        'organization_counsellor_id' => $affiliation->id,
    ], $compensationOverrides));

    $member = User::factory()->create();
    $organizationMember = OrganizationMember::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $member->id,
    ]);
    OrganizationMemberBillingConfig::factory()->create([
        'organization_member_id' => $organizationMember->id,
        'mode' => OrganizationMemberBillingModeEnum::retainer->value,
    ]);

    $therapy = Therapy::factory()->create(array_merge([
        'addedby_type' => User::class,
        'addedby_id' => $member->id,
        'counsellor_id' => $counsellor->id,
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 100, 'currency' => 'GHS'],
    ], $therapyOverrides));

    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);

    return [$organization, $counsellor, $therapy, $session, $member];
}

test('records an invoice and a line for a retainer-covered held session', function () {
    config(['settings.platform_fee_percentage' => 10]);
    [$organization, $counsellor, , $session] = aRetainerCoveredSession([
        'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
        'amount' => 5000,
        'currency' => 'GHS',
    ]);

    RecordOrganizationInvoiceLineForSessionAction::new()->execute($session);

    $this->assertDatabaseCount('organization_invoices', 1);
    $invoice = OrganizationInvoice::first();
    expect($invoice->organization_id)->toBe($organization->id);
    expect($invoice->currency)->toBe('GHS');
    expect($invoice->status)->toBe(OrganizationInvoiceStatusEnum::open->value);
    expect($invoice->period_start->isSameDay(now()->startOfMonth()))->toBeTrue();

    $this->assertDatabaseCount('organization_invoice_lines', 1);
    $line = OrganizationInvoiceLine::first();
    expect($line->organization_invoice_id)->toBe($invoice->id);
    expect($line->session_id)->toBe($session->id);
    expect($line->counsellor_id)->toBe($counsellor->id);
    // Listed rate GHS 100 (10000 minor units) -- fixed share 5000, fee 10% of 10000 = 1000.
    expect($line->net_amount)->toBe(5000);
    expect($line->fee_amount)->toBe(1000);
    expect($line->currency)->toBe('GHS');
});

test('a FREE compensation still records a line -- net 0, fee on the listed rate', function () {
    config(['settings.platform_fee_percentage' => 10]);
    [, , , $session] = aRetainerCoveredSession([
        'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
    ]);

    RecordOrganizationInvoiceLineForSessionAction::new()->execute($session);

    $line = OrganizationInvoiceLine::first();
    expect($line->net_amount)->toBe(0);
    expect($line->fee_amount)->toBe(1000);
});

test('a PERCENTAGE negotiatedRate compensation computes the share off the negotiated rate, fee off the listed rate', function () {
    config(['settings.platform_fee_percentage' => 10]);
    [, , , $session] = aRetainerCoveredSession([
        'type' => OrganizationCounsellorCompensationTypeEnum::percentage->value,
        'basis' => OrganizationCounsellorCompensationBasisEnum::negotiatedRate->value,
        'percentage' => 50,
        'negotiated_rate_amount' => 30000,
    ]);

    RecordOrganizationInvoiceLineForSessionAction::new()->execute($session);

    $line = OrganizationInvoiceLine::first();
    expect($line->net_amount)->toBe(15000);
    expect($line->fee_amount)->toBe(1000);
});

test('a second retainer-covered session in the same org/currency/period reuses the same open invoice', function () {
    [$organization, $counsellor, $therapy, $sessionOne] = aRetainerCoveredSession([
        'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
        'amount' => 5000,
        'currency' => 'GHS',
    ]);
    $sessionTwo = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);

    RecordOrganizationInvoiceLineForSessionAction::new()->execute($sessionOne);
    RecordOrganizationInvoiceLineForSessionAction::new()->execute($sessionTwo);

    $this->assertDatabaseCount('organization_invoices', 1);
    $this->assertDatabaseCount('organization_invoice_lines', 2);
});

// Architect finding (blocking correction to Decision 3): different counsellors covered by the
// same org can have listed rates in different currencies -- summing mixed currencies into one
// invoice.amount would silently corrupt the total, so currency is part of the lookup key.
test('two currencies for the same org/period get two separate invoices, never merged', function () {
    [$organization, , , $sessionGhs] = aRetainerCoveredSession([
        'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
        'amount' => 5000,
        'currency' => 'GHS',
    ]);

    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $affiliation = OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellor->id,
        'status' => OrganizationCounsellorStatusEnum::active->value,
    ]);
    OrganizationCounsellorCompensation::factory()->create([
        'organization_counsellor_id' => $affiliation->id,
        'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
        'amount' => 3000,
        'currency' => 'USD',
    ]);
    $member = User::factory()->create();
    $organizationMember = OrganizationMember::factory()->create(['organization_id' => $organization->id, 'user_id' => $member->id]);
    OrganizationMemberBillingConfig::factory()->create([
        'organization_member_id' => $organizationMember->id,
        'mode' => OrganizationMemberBillingModeEnum::retainer->value,
    ]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $member->id,
        'counsellor_id' => $counsellor->id,
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'USD'],
    ]);
    $sessionUsd = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);

    RecordOrganizationInvoiceLineForSessionAction::new()->execute($sessionGhs);
    RecordOrganizationInvoiceLineForSessionAction::new()->execute($sessionUsd);

    $this->assertDatabaseCount('organization_invoices', 2);
    expect(OrganizationInvoice::pluck('currency')->sort()->values()->all())->toBe(['GHS', 'USD']);
});

test('calling execute twice for the same session does not create a duplicate line', function () {
    [, , , $session] = aRetainerCoveredSession();

    RecordOrganizationInvoiceLineForSessionAction::new()->execute($session);
    RecordOrganizationInvoiceLineForSessionAction::new()->execute($session);

    $this->assertDatabaseCount('organization_invoice_lines', 1);
});

test('a non-retainer (pay-per-use) org member never gets an invoice line', function () {
    [, , , $session, $member] = aRetainerCoveredSession();
    $organizationMember = OrganizationMember::first();
    OrganizationMemberBillingConfig::factory()->create([
        'organization_member_id' => $organizationMember->id,
        'mode' => OrganizationMemberBillingModeEnum::payPerUse->value,
        'effective_from' => now()->addMinute(),
    ]);

    RecordOrganizationInvoiceLineForSessionAction::new()->execute($session);

    $this->assertDatabaseCount('organization_invoices', 0);
    $this->assertDatabaseCount('organization_invoice_lines', 0);
});

test('a therapy added by a counsellor (not a User) is skipped entirely', function () {
    $counsellorUser = User::factory()->create();
    $addingCounsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => Counsellor::class,
        'addedby_id' => $addingCounsellor->id,
        'counsellor_id' => $addingCounsellor->id,
    ]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);

    RecordOrganizationInvoiceLineForSessionAction::new()->execute($session);

    $this->assertDatabaseCount('organization_invoices', 0);
});

test('a counsellor with no active compensation terms logs a warning and creates nothing', function () {
    Log::spy();
    $organization = Organization::factory()->create(['is_consumer' => true, 'verified_at' => now()]);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellor->id,
        'status' => OrganizationCounsellorStatusEnum::active->value,
    ]);
    $member = User::factory()->create();
    $organizationMember = OrganizationMember::factory()->create(['organization_id' => $organization->id, 'user_id' => $member->id]);
    OrganizationMemberBillingConfig::factory()->create([
        'organization_member_id' => $organizationMember->id,
        'mode' => OrganizationMemberBillingModeEnum::retainer->value,
    ]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $member->id,
        'counsellor_id' => $counsellor->id,
    ]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);

    RecordOrganizationInvoiceLineForSessionAction::new()->execute($session);

    $this->assertDatabaseCount('organization_invoice_lines', 0);
    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message) => str_contains($message, 'no active compensation terms'))
        ->once();
});

test('never throws, even on an unexpected internal failure', function () {
    [, , , $session] = aRetainerCoveredSession();
    // No currency resolvable at all -- forces the "no resolvable currency" guard.
    $session->for->update(['payment_data' => null]);

    expect(fn () => RecordOrganizationInvoiceLineForSessionAction::new()->execute($session))
        ->not->toThrow(Throwable::class);

    $this->assertDatabaseCount('organization_invoice_lines', 0);
});
