<?php

use App\Actions\Organization\CaptureOrganizationPaymentInstrumentAction;
use App\Models\Organization;
use App\Models\OrganizationPaymentInstrument;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;

// TT-7.3b-a/SCRUM-231: the "no separate save/tokenize call needed" design -- a successful
// verify/webhook response already carries everything needed (architect decision, SCRUM-230
// review); this action's only job is persisting it.

function anOrgRegistrationTransaction(?Organization $organization = null): Transaction
{
    $organization ??= Organization::factory()->create();

    return Transaction::factory()->create([
        'for_type' => Organization::class,
        'for_id' => $organization->id,
        'user_id' => User::factory(),
        'amount' => 100,
        'currency' => 'GHS',
    ]);
}

function aReusableAuthorization(array $overrides = []): array
{
    return array_merge([
        'authorization_code' => 'AUTH_test_1',
        'last4' => '4242',
        'card_type' => 'visa',
        'bank' => 'Test Bank',
        'exp_month' => '12',
        'exp_year' => '2030',
        'reusable' => true,
    ], $overrides);
}

test('captures a reusable authorization into a new organization payment instrument', function () {
    $organization = Organization::factory()->create();
    $transaction = anOrgRegistrationTransaction($organization);

    CaptureOrganizationPaymentInstrumentAction::new()->execute($transaction, aReusableAuthorization());

    $instrument = OrganizationPaymentInstrument::query()->where('organization_id', $organization->id)->first();
    expect($instrument)->not->toBeNull();
    expect($instrument->authorization_code)->toBe('AUTH_test_1');
    expect($instrument->masked_card_number)->toBe('**** 4242');
    expect($instrument->card_type)->toBe('visa');
    expect($instrument->bank)->toBe('Test Bank');
    expect($instrument->exp_month)->toBe('12');
    expect($instrument->exp_year)->toBe('2030');
    expect($instrument->currency)->toBe('GHS');
    expect($instrument->pending_credit_amount)->toBe(100);
});

test('re-registering replaces the existing instrument in place rather than creating a second row', function () {
    $organization = Organization::factory()->create();
    $first = anOrgRegistrationTransaction($organization);
    CaptureOrganizationPaymentInstrumentAction::new()->execute($first, aReusableAuthorization(['authorization_code' => 'AUTH_first']));

    $second = anOrgRegistrationTransaction($organization);
    CaptureOrganizationPaymentInstrumentAction::new()->execute($second, aReusableAuthorization(['authorization_code' => 'AUTH_second']));

    $this->assertDatabaseCount('organization_payment_instruments', 1);
    expect(OrganizationPaymentInstrument::first()->authorization_code)->toBe('AUTH_second');
});

// Reviewer finding: re-registration (e.g. a declined/expired card) must never silently clobber a
// still-outstanding prior verification-charge credit -- both are genuinely owed back to the org.
test('re-registering in the same currency accumulates the pending credit rather than overwriting it', function () {
    $organization = Organization::factory()->create();
    $first = anOrgRegistrationTransaction($organization);
    CaptureOrganizationPaymentInstrumentAction::new()->execute($first, aReusableAuthorization(['authorization_code' => 'AUTH_first']));

    $second = anOrgRegistrationTransaction($organization);
    CaptureOrganizationPaymentInstrumentAction::new()->execute($second, aReusableAuthorization(['authorization_code' => 'AUTH_second']));

    expect(OrganizationPaymentInstrument::first()->pending_credit_amount)->toBe(200);
});

test('re-registering in a different currency does not sum minor units across currencies', function () {
    $organization = Organization::factory()->create();
    $first = anOrgRegistrationTransaction($organization);
    CaptureOrganizationPaymentInstrumentAction::new()->execute($first, aReusableAuthorization(['authorization_code' => 'AUTH_first']));

    $second = Transaction::factory()->create([
        'for_type' => Organization::class,
        'for_id' => $organization->id,
        'user_id' => User::factory(),
        'amount' => 150,
        'currency' => 'USD',
    ]);
    CaptureOrganizationPaymentInstrumentAction::new()->execute($second, aReusableAuthorization(['authorization_code' => 'AUTH_second']));

    $instrument = OrganizationPaymentInstrument::first();
    expect($instrument->currency)->toBe('USD');
    expect($instrument->pending_credit_amount)->toBe(150);
});

// Security-engineer finding: the same physical card verified for a second organization must not
// crash the whole success-recording transaction via an uncaught unique-constraint violation.
test('the same authorization code cannot be captured for a second organization', function () {
    $firstOrg = Organization::factory()->create();
    $firstTransaction = anOrgRegistrationTransaction($firstOrg);
    CaptureOrganizationPaymentInstrumentAction::new()->execute($firstTransaction, aReusableAuthorization(['authorization_code' => 'AUTH_shared']));

    $secondOrg = Organization::factory()->create();
    $secondTransaction = anOrgRegistrationTransaction($secondOrg);

    expect(fn () => CaptureOrganizationPaymentInstrumentAction::new()->execute($secondTransaction, aReusableAuthorization(['authorization_code' => 'AUTH_shared'])))
        ->not->toThrow(Exception::class);

    $this->assertDatabaseCount('organization_payment_instruments', 1);
    expect(OrganizationPaymentInstrument::first()->organization_id)->toBe($firstOrg->id);
});

test('a non-reusable authorization is not persisted', function () {
    $transaction = anOrgRegistrationTransaction();

    CaptureOrganizationPaymentInstrumentAction::new()->execute($transaction, aReusableAuthorization(['reusable' => false]));

    $this->assertDatabaseCount('organization_payment_instruments', 0);
});

test('a missing authorization_code is not persisted', function () {
    $transaction = anOrgRegistrationTransaction();

    CaptureOrganizationPaymentInstrumentAction::new()->execute($transaction, aReusableAuthorization(['authorization_code' => null]));

    $this->assertDatabaseCount('organization_payment_instruments', 0);
});

test('a transaction whose subject is not an organization is left untouched', function () {
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
    ]);
    $transaction = Transaction::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
    ]);

    CaptureOrganizationPaymentInstrumentAction::new()->execute($transaction, aReusableAuthorization());

    $this->assertDatabaseCount('organization_payment_instruments', 0);
});
