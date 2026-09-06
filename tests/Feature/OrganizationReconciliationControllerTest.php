<?php

use App\Enums\CounsellorPayoutStatusEnum;
use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\OrganizationInvoiceStatusEnum;
use App\Models\Counsellor;
use App\Models\CounsellorEarning;
use App\Models\CounsellorPayout;
use App\Models\GroupTherapy;
use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Models\OrganizationInvoiceLine;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;

// TT-7.3b-j/SCRUM-241: org-admin reconciliation view -- mirrors OrganizationDashboardControllerTest's
// own admin-gating/cross-org-isolation coverage shape (TT-6.6a), applied to the new
// pay-per-use-transactions + retainer-invoices lists.

function anOrgAdmin(array $overrides = []): array
{
    $organization = Organization::factory()->create(array_merge(['is_consumer' => true, 'verified_at' => now()], $overrides));
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

    return [$organization, $owner];
}

test('an org admin can load the reconciliation page', function () {
    [$organization, $owner] = anOrgAdmin();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory(), 'counsellor_id' => $counsellor->id]);
    Transaction::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id, 'organization_id' => $organization->id]);
    $invoice = OrganizationInvoice::factory()->create(['organization_id' => $organization->id]);
    OrganizationInvoiceLine::factory()->create(['organization_invoice_id' => $invoice->id]);

    $this->actingAs($owner);

    $response = $this->get(route('organizations.reconciliation', ['organizationId' => $organization->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->component('Organization/Reconciliation')
        ->where('organization.id', $organization->id)
        ->has('financedTransactions.data', 1)
        ->has('retainerInvoices.data', 1)
    );
});

// Security-engineer finding: subjectLabel must be an opaque "Therapy #<id>" reference, NEVER the
// therapy's own client-authored `name` -- a real anonymity/content leak to a viewer (an org
// billing admin) with no other legitimate route to therapy content at all.
test('a financed transaction row exposes an opaque subject label, never the therapy\'s own client-authored name', function () {
    [$organization, $owner] = anOrgAdmin();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory(), 'name' => 'Jane Counsellor']);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
        'name' => 'Anxiety Support',
        'anonymous' => true,
    ]);
    $transaction = Transaction::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'organization_id' => $organization->id,
        'amount' => 6000,
        'currency' => 'GHS',
        'status' => 'SUCCESS',
    ]);
    $payout = CounsellorPayout::factory()->create(['counsellor_id' => $counsellor->id, 'status' => CounsellorPayoutStatusEnum::succeeded->value]);
    CounsellorEarning::factory()->create([
        'transaction_id' => $transaction->id,
        'counsellor_id' => $counsellor->id,
        'net_amount' => 5000,
        'fee_amount' => 1000,
        'counsellor_payout_id' => $payout->id,
    ]);

    $this->actingAs($owner);

    $response = $this->get(route('organizations.reconciliation', ['organizationId' => $organization->id]));

    $response->assertInertia(function ($page) use ($therapy) {
        $row = collect($page->toArray()['props']['financedTransactions']['data'])->first();

        expect($row['subjectLabel'])->toBe("Therapy #{$therapy->id}");
        expect($row['subjectLabel'])->not->toContain('Anxiety Support');
        // The counsellor's own name is professional/public info -- not a client-anonymity concern.
        expect($row['counsellorName'])->toBe('Jane Counsellor');
        expect($row['amount'])->toBe(6000);
        expect($row['counsellorShare'])->toBe(5000);
        expect($row['platformFee'])->toBe(1000);
        expect($row['payoutStatus'])->toBe(CounsellorPayoutStatusEnum::succeeded->value);
    });
});

test('a retainer invoice row exposes its lines with an opaque session label, never the session\'s own client-authored name', function () {
    [$organization, $owner] = anOrgAdmin();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory(), 'name' => 'Jane Counsellor']);
    $session = Session::factory()->create(['name' => 'My private struggle with anxiety']);
    $invoice = OrganizationInvoice::factory()->create([
        'organization_id' => $organization->id,
        'status' => OrganizationInvoiceStatusEnum::settled->value,
        'amount' => 5500,
    ]);
    OrganizationInvoiceLine::factory()->create([
        'organization_invoice_id' => $invoice->id,
        'session_id' => $session->id,
        'counsellor_id' => $counsellor->id,
        'net_amount' => 5000,
        'fee_amount' => 500,
    ]);

    $this->actingAs($owner);

    $response = $this->get(route('organizations.reconciliation', ['organizationId' => $organization->id]));

    $response->assertInertia(function ($page) use ($session) {
        $invoiceRow = collect($page->toArray()['props']['retainerInvoices']['data'])->first();

        expect($invoiceRow['status'])->toBe(OrganizationInvoiceStatusEnum::settled->value);
        expect($invoiceRow['amount'])->toBe(5500);
        $line = collect($invoiceRow['lines'])->first();
        expect($line['sessionLabel'])->toBe("Session #{$session->id}");
        expect($line['sessionLabel'])->not->toContain('anxiety');
        expect($line['counsellorName'])->toBe('Jane Counsellor');
        expect($line['netAmount'])->toBe(5000);
        expect($line['feeAmount'])->toBe(500);
    });
});

test('an open (still-accruing) invoice exposes a computed accruedAmount, not just a null amount', function () {
    [$organization, $owner] = anOrgAdmin();
    $invoice = OrganizationInvoice::factory()->create([
        'organization_id' => $organization->id,
        'status' => OrganizationInvoiceStatusEnum::open->value,
        'amount' => null,
    ]);
    OrganizationInvoiceLine::factory()->create(['organization_invoice_id' => $invoice->id, 'net_amount' => 3000, 'fee_amount' => 400]);
    OrganizationInvoiceLine::factory()->create(['organization_invoice_id' => $invoice->id, 'net_amount' => 1000, 'fee_amount' => 100]);

    $this->actingAs($owner);

    $response = $this->get(route('organizations.reconciliation', ['organizationId' => $organization->id]));

    $response->assertInertia(function ($page) {
        $invoiceRow = collect($page->toArray()['props']['retainerInvoices']['data'])->first();

        expect($invoiceRow['amount'])->toBeNull();
        expect($invoiceRow['accruedAmount'])->toBe(4500);
    });
});

test('a settlement transaction (for an OrganizationInvoice) is excluded from the pay-per-use transactions list', function () {
    [$organization, $owner] = anOrgAdmin();
    $invoice = OrganizationInvoice::factory()->create(['organization_id' => $organization->id]);
    Transaction::factory()->create(['for_type' => OrganizationInvoice::class, 'for_id' => $invoice->id, 'organization_id' => $organization->id]);

    $this->actingAs($owner);

    $response = $this->get(route('organizations.reconciliation', ['organizationId' => $organization->id]));

    $response->assertInertia(fn ($page) => $page->has('financedTransactions.data', 0));
});

test('a GroupTherapy financed transaction shows no counsellor (org billing was never built for group therapies)', function () {
    [$organization, $owner] = anOrgAdmin();
    $groupTherapy = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory(), 'name' => 'Group Session']);
    Transaction::factory()->create(['for_type' => GroupTherapy::class, 'for_id' => $groupTherapy->id, 'organization_id' => $organization->id]);

    $this->actingAs($owner);

    $response = $this->get(route('organizations.reconciliation', ['organizationId' => $organization->id]));

    $response->assertInertia(function ($page) use ($groupTherapy) {
        $row = collect($page->toArray()['props']['financedTransactions']['data'])->first();

        expect($row['subjectType'])->toBe('GroupTherapy');
        expect($row['subjectLabel'])->toBe("Group Therapy #{$groupTherapy->id}");
        expect($row['counsellorName'])->toBeNull();
    });
});

test('a billing-suspended organization is flagged on the organization prop', function () {
    [$organization, $owner] = anOrgAdmin();
    $organization->suspendBilling('Retainer invoice settlement failed.');

    $this->actingAs($owner);

    $response = $this->get(route('organizations.reconciliation', ['organizationId' => $organization->id]));

    $response->assertInertia(fn ($page) => $page->where('organization.isBillingSuspended', true));
});

test('a non-admin is redirected home rather than seeing a raw error page', function () {
    [$organization] = anOrgAdmin();
    $outsider = User::factory()->create();

    $this->actingAs($outsider);

    $response = $this->get(route('organizations.reconciliation', ['organizationId' => $organization->id]));

    $response->assertRedirect(route('home'));
});

test('a nonexistent organization redirects home rather than a raw error page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('organizations.reconciliation', ['organizationId' => 999999]));

    $response->assertRedirect(route('home'));
});

// Security-critical: an admin of a DIFFERENT organization must not see this one's financed
// transactions/invoices just by being some org's admin, and a different org's own transactions
// must never leak into this one's list either.
test('an admin of a different organization is redirected home, not shown this one\'s reconciliation data', function () {
    [$organizationA] = anOrgAdmin();
    [$organizationB, $adminOfB] = anOrgAdmin();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);
    Transaction::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id, 'organization_id' => $organizationA->id]);

    $this->actingAs($adminOfB);

    $response = $this->get(route('organizations.reconciliation', ['organizationId' => $organizationA->id]));

    $response->assertRedirect(route('home'));

    // And org B's own (empty) reconciliation view must never see org A's transaction.
    $ownResponse = $this->get(route('organizations.reconciliation', ['organizationId' => $organizationB->id]));
    $ownResponse->assertInertia(fn ($page) => $page->has('financedTransactions.data', 0));
});

test('a guest is redirected to login', function () {
    [$organization] = anOrgAdmin();

    $response = $this->get(route('organizations.reconciliation', ['organizationId' => $organization->id]));

    $response->assertRedirect(route('login'));
});

test('an org admin can fetch the paginated transactions list via the real JSON route', function () {
    [$organization, $owner] = anOrgAdmin();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);
    Transaction::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id, 'organization_id' => $organization->id]);

    $this->actingAs($owner);

    $response = $this->getJson(route('organizations.reconciliation.transactions', ['organizationId' => $organization->id]));

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});

test('an org admin can fetch the paginated invoices list via the real JSON route', function () {
    [$organization, $owner] = anOrgAdmin();
    OrganizationInvoice::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs($owner);

    $response = $this->getJson(route('organizations.reconciliation.invoices', ['organizationId' => $organization->id]));

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});

// Reviewer/security-engineer findings: the cross-org isolation test above only exercises the
// Inertia index() route -- these pin the identical guard down on the two dedicated JSON endpoints
// directly, since they go through the same OrganizationService methods but were previously
// untested at these specific routes.
test('an admin of a different organization cannot fetch this org\'s transactions via the JSON route', function () {
    [$organizationA] = anOrgAdmin();
    [, $adminOfB] = anOrgAdmin();

    $this->actingAs($adminOfB);

    $response = $this->getJson(route('organizations.reconciliation.transactions', ['organizationId' => $organizationA->id]));

    $response->assertForbidden();
});

test('an admin of a different organization cannot fetch this org\'s invoices via the JSON route', function () {
    [$organizationA] = anOrgAdmin();
    [, $adminOfB] = anOrgAdmin();

    $this->actingAs($adminOfB);

    $response = $this->getJson(route('organizations.reconciliation.invoices', ['organizationId' => $organizationA->id]));

    $response->assertForbidden();
});

test('a non-admin cannot fetch financed transactions or invoices via either JSON route', function () {
    [$organization] = anOrgAdmin();
    $outsider = User::factory()->create();

    $this->actingAs($outsider);

    $this->getJson(route('organizations.reconciliation.transactions', ['organizationId' => $organization->id]))->assertForbidden();
    $this->getJson(route('organizations.reconciliation.invoices', ['organizationId' => $organization->id]))->assertForbidden();
});

// Reviewer finding: the whole point of repointing each initial paginator's path at its own
// dedicated JSON route (mirrored from organizations.dashboard's identical trick) is that a later
// "load more" axios GET gets JSON back, not this page's own full HTML response -- pin that the
// initial props actually carry that repointed path, not just that the page loads.
test('the initial paginators are repointed at their own dedicated JSON routes, not this page\'s own route', function () {
    [$organization, $owner] = anOrgAdmin();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);
    Transaction::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id, 'organization_id' => $organization->id]);
    OrganizationInvoice::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs($owner);

    $response = $this->get(route('organizations.reconciliation', ['organizationId' => $organization->id]));

    $expectedTransactionsPath = route('organizations.reconciliation.transactions', ['organizationId' => $organization->id]);
    $expectedInvoicesPath = route('organizations.reconciliation.invoices', ['organizationId' => $organization->id]);

    $response->assertInertia(function ($page) use ($expectedTransactionsPath, $expectedInvoicesPath) {
        $props = $page->toArray()['props'];

        expect($props['financedTransactions']['meta']['path'])->toBe($expectedTransactionsPath);
        expect($props['retainerInvoices']['meta']['path'])->toBe($expectedInvoicesPath);
    });
});
