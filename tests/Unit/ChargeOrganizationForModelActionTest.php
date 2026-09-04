<?php

use App\Actions\Transaction\ChargeOrganizationForModelAction;
use App\DTOs\TransactionDTO;
use App\Enums\OrganizationCounsellorCompensationBasisEnum;
use App\Enums\OrganizationCounsellorCompensationTypeEnum;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapyPerPaymentEnum;
use App\Enums\TherapySessionTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Enums\TransactionStatusEnum;
use App\Exceptions\TransactionException;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\OrganizationCounsellorCompensation;
use App\Models\OrganizationPaymentInstrument;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;

// TT-7.3b-b/SCRUM-233: the ONE shared mechanism TT-7.3b-c/-e will both call into. This test file
// pins down the "compute actual cost, charge the saved instrument, record a Transaction" contract
// directly (mirrors TT-7.6a/b's own pattern of unit-testing the shared action ahead of its callers).

function anOrgWithCounsellorAndInstrument(array $compensationOverrides = []): array
{
    $organization = Organization::factory()->create();
    OrganizationPaymentInstrument::factory()->create(['organization_id' => $organization->id]);

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

    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $member->id,
        'counsellor_id' => $counsellor->id,
        'session_type' => TherapySessionTypeEnum::once->value,
        'status' => TherapyStatusEnum::in_session->value,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => TherapyPerPaymentEnum::therapy->value, 'amount' => 100, 'currency' => 'GHS'],
    ]);

    return [$organization, $counsellor, $therapy, $member];
}

test('charges a FIXED compensation counsellor the fixed share plus a fee on the listed rate', function () {
    [$organization, , $therapy, $member] = anOrgWithCounsellorAndInstrument([
        'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
        'amount' => 5000,
        'currency' => 'GHS',
    ]);
    config(['settings.platform_fee_percentage' => 10]);
    // Listed rate is GHS 100 (10000 minor units) -- fee = 10% of 10000 = 1000. Share = 5000 fixed.
    // Total = 6000.
    Http::fake(['*/transaction/charge_authorization' => Http::response([
        'status' => true,
        'data' => ['reference' => 'ref_fixed', 'status' => 'success', 'amount' => 6000, 'currency' => 'GHS', 'gateway_response' => 'Approved'],
    ], 200)]);

    $transaction = ChargeOrganizationForModelAction::new()->execute(TransactionDTO::new()->fromArray([
        'user' => $member,
        'for' => $therapy,
        'organization' => $organization,
    ]));

    expect($transaction->amount)->toBe(6000);
    expect($transaction->currency)->toBe('GHS');
    expect($transaction->organization_id)->toBe($organization->id);
    expect($transaction->user_id)->toBe($member->id);
    expect($transaction->for_type)->toBe(Therapy::class);
    expect($transaction->status)->toBe(TransactionStatusEnum::success->value);
});

test('a FREE compensation counsellor still charges the org the platform fee alone', function () {
    [$organization, , $therapy, $member] = anOrgWithCounsellorAndInstrument([
        'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
    ]);
    config(['settings.platform_fee_percentage' => 10]);
    // Listed rate GHS 100 (10000 minor units) -- fee = 1000, share = 0. Total = 1000.
    Http::fake(['*/transaction/charge_authorization' => Http::response([
        'status' => true,
        'data' => ['reference' => 'ref_free', 'status' => 'success', 'amount' => 1000, 'currency' => 'GHS', 'gateway_response' => 'Approved'],
    ], 200)]);

    $transaction = ChargeOrganizationForModelAction::new()->execute(TransactionDTO::new()->fromArray([
        'user' => $member,
        'for' => $therapy,
        'organization' => $organization,
    ]));

    expect($transaction->amount)->toBe(1000);
});

test('a PERCENTAGE counsellorRate compensation charges a percentage of the listed rate plus the fee', function () {
    [$organization, , $therapy, $member] = anOrgWithCounsellorAndInstrument([
        'type' => OrganizationCounsellorCompensationTypeEnum::percentage->value,
        'basis' => OrganizationCounsellorCompensationBasisEnum::counsellorRate->value,
        'percentage' => 70,
    ]);
    config(['settings.platform_fee_percentage' => 10]);
    // Listed rate GHS 100 (10000 minor units) -- share = 70% = 7000, fee = 10% of 10000 = 1000.
    // Total = 8000.
    Http::fake(['*/transaction/charge_authorization' => Http::response([
        'status' => true,
        'data' => ['reference' => 'ref_pct', 'status' => 'success', 'amount' => 8000, 'currency' => 'GHS', 'gateway_response' => 'Approved'],
    ], 200)]);

    $transaction = ChargeOrganizationForModelAction::new()->execute(TransactionDTO::new()->fromArray([
        'user' => $member,
        'for' => $therapy,
        'organization' => $organization,
    ]));

    expect($transaction->amount)->toBe(8000);
});

// Architect finding: this is the one branch where "fee is on the listed rate, never the
// compensation basis" is least self-evident -- a negotiated rate can differ substantially from
// the counsellor's own listed price, so this pins down that the fee still uses the LISTED rate
// (GHS 100), not the negotiated one (GHS 300), even though the share itself uses the negotiated
// figure.
test('a PERCENTAGE negotiatedRate compensation charges the fee on the listed rate, not the negotiated rate', function () {
    [$organization, , $therapy, $member] = anOrgWithCounsellorAndInstrument([
        'type' => OrganizationCounsellorCompensationTypeEnum::percentage->value,
        'basis' => OrganizationCounsellorCompensationBasisEnum::negotiatedRate->value,
        'percentage' => 50,
        'negotiated_rate_amount' => 30000,
    ]);
    config(['settings.platform_fee_percentage' => 10]);
    // Share = 50% of the NEGOTIATED 30000 = 15000. Fee = 10% of the LISTED rate (GHS 100 =
    // 10000 minor units) = 1000, never 10% of the negotiated amount. Total = 16000.
    Http::fake(['*/transaction/charge_authorization' => Http::response([
        'status' => true,
        'data' => ['reference' => 'ref_negotiated', 'status' => 'success', 'amount' => 16000, 'currency' => 'GHS', 'gateway_response' => 'Approved'],
    ], 200)]);

    $transaction = ChargeOrganizationForModelAction::new()->execute(TransactionDTO::new()->fromArray([
        'user' => $member,
        'for' => $therapy,
        'organization' => $organization,
    ]));

    expect($transaction->amount)->toBe(16000);
});

test('a PER_SESSION-payable session resolves its counsellor and listed amount through its parent therapy', function () {
    [$organization, $counsellor, $therapy, $member] = anOrgWithCounsellorAndInstrument([
        'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
        'amount' => 2000,
        'currency' => 'GHS',
    ]);
    $therapy->update(['payment_data' => ['per' => TherapyPerPaymentEnum::session->value, 'amount' => 50, 'currency' => 'GHS']]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);
    config(['settings.platform_fee_percentage' => 10]);
    // Listed rate GHS 50 (5000 minor units) -- fee = 500, share = 2000 fixed. Total = 2500.
    Http::fake(['*/transaction/charge_authorization' => Http::response([
        'status' => true,
        'data' => ['reference' => 'ref_session', 'status' => 'success', 'amount' => 2500, 'currency' => 'GHS', 'gateway_response' => 'Approved'],
    ], 200)]);

    $transaction = ChargeOrganizationForModelAction::new()->execute(TransactionDTO::new()->fromArray([
        'user' => $member,
        'for' => $session,
        'organization' => $organization,
    ]));

    expect($transaction->amount)->toBe(2500);
    expect($transaction->for_type)->toBe(Session::class);
    expect($transaction->for_id)->toBe($session->id);
});

test('no organization throws', function () {
    [, , $therapy, $member] = anOrgWithCounsellorAndInstrument();

    ChargeOrganizationForModelAction::new()->execute(TransactionDTO::new()->fromArray([
        'user' => $member,
        'for' => $therapy,
    ]));
})->throws(TransactionException::class);

test('a group therapy is rejected -- not yet supported', function () {
    $organization = Organization::factory()->create();
    OrganizationPaymentInstrument::factory()->create(['organization_id' => $organization->id]);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
    ]);

    ChargeOrganizationForModelAction::new()->execute(TransactionDTO::new()->fromArray([
        'user' => User::factory()->create(),
        'for' => $groupTherapy,
        'organization' => $organization,
    ]));
})->throws(TransactionException::class);

// Reviewer finding: the GroupTherapy rejection must apply to a PER_SESSION session of a
// GroupTherapy too, not just a GroupTherapy passed in directly.
test('a session belonging to a group therapy is also rejected', function () {
    $organization = Organization::factory()->create();
    OrganizationPaymentInstrument::factory()->create(['organization_id' => $organization->id]);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
    ]);
    $session = Session::factory()->create(['for_id' => $groupTherapy->id, 'for_type' => GroupTherapy::class]);

    ChargeOrganizationForModelAction::new()->execute(TransactionDTO::new()->fromArray([
        'user' => User::factory()->create(),
        'for' => $session,
        'organization' => $organization,
    ]));
})->throws(TransactionException::class);

test('a therapy with no assigned counsellor throws', function () {
    $organization = Organization::factory()->create();
    OrganizationPaymentInstrument::factory()->create(['organization_id' => $organization->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => null,
    ]);

    ChargeOrganizationForModelAction::new()->execute(TransactionDTO::new()->fromArray([
        'user' => User::factory()->create(),
        'for' => $therapy,
        'organization' => $organization,
    ]));
})->throws(TransactionException::class);

test('a counsellor with no active compensation terms with the organization throws', function () {
    $organization = Organization::factory()->create();
    OrganizationPaymentInstrument::factory()->create(['organization_id' => $organization->id]);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
        'payment_data' => ['per' => TherapyPerPaymentEnum::therapy->value, 'amount' => 100, 'currency' => 'GHS'],
    ]);

    ChargeOrganizationForModelAction::new()->execute(TransactionDTO::new()->fromArray([
        'user' => User::factory()->create(),
        'for' => $therapy,
        'organization' => $organization,
    ]));
})->throws(TransactionException::class);

// Security-engineer finding: a counsellor's affiliation with a DIFFERENT organization must never
// be treated as covering the one actually being billed.
test('a counsellor affiliated only with a different organization throws', function () {
    $billingOrganization = Organization::factory()->create();
    OrganizationPaymentInstrument::factory()->create(['organization_id' => $billingOrganization->id]);

    $otherOrganization = Organization::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $affiliation = OrganizationCounsellor::factory()->create([
        'organization_id' => $otherOrganization->id,
        'counsellor_id' => $counsellor->id,
        'status' => OrganizationCounsellorStatusEnum::active->value,
    ]);
    OrganizationCounsellorCompensation::factory()->create(['organization_counsellor_id' => $affiliation->id]);

    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
        'payment_data' => ['per' => TherapyPerPaymentEnum::therapy->value, 'amount' => 100, 'currency' => 'GHS'],
    ]);

    ChargeOrganizationForModelAction::new()->execute(TransactionDTO::new()->fromArray([
        'user' => User::factory()->create(),
        'for' => $therapy,
        'organization' => $billingOrganization,
    ]));
})->throws(TransactionException::class);

test('an organization with no payment instrument on file throws', function () {
    $organization = Organization::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $affiliation = OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellor->id,
        'status' => OrganizationCounsellorStatusEnum::active->value,
    ]);
    OrganizationCounsellorCompensation::factory()->create(['organization_counsellor_id' => $affiliation->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
        'payment_data' => ['per' => TherapyPerPaymentEnum::therapy->value, 'amount' => 100, 'currency' => 'GHS'],
    ]);

    ChargeOrganizationForModelAction::new()->execute(TransactionDTO::new()->fromArray([
        'user' => User::factory()->create(),
        'for' => $therapy,
        'organization' => $organization,
    ]));
})->throws(TransactionException::class);

test('a failed Paystack charge_authorization call is surfaced as a clean TransactionException', function () {
    [$organization, , $therapy, $member] = anOrgWithCounsellorAndInstrument([
        'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
        'amount' => 5000,
        'currency' => 'GHS',
    ]);
    Http::fake(['*/transaction/charge_authorization' => Http::response(['status' => false], 502)]);

    ChargeOrganizationForModelAction::new()->execute(TransactionDTO::new()->fromArray([
        'user' => $member,
        'for' => $therapy,
        'organization' => $organization,
    ]));
})->throws(TransactionException::class);

test('a declined charge records the transaction as failed, not success', function () {
    [$organization, , $therapy, $member] = anOrgWithCounsellorAndInstrument([
        'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
        'amount' => 5000,
        'currency' => 'GHS',
    ]);
    Http::fake(['*/transaction/charge_authorization' => Http::response([
        'status' => true,
        'data' => ['reference' => 'ref_declined', 'status' => 'failed', 'gateway_response' => 'Declined'],
    ], 200)]);

    $transaction = ChargeOrganizationForModelAction::new()->execute(TransactionDTO::new()->fromArray([
        'user' => $member,
        'for' => $therapy,
        'organization' => $organization,
    ]));

    expect($transaction->status)->toBe(TransactionStatusEnum::failed->value);
});

test('an abandoned charge records the transaction as abandoned', function () {
    [$organization, , $therapy, $member] = anOrgWithCounsellorAndInstrument([
        'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
        'amount' => 5000,
        'currency' => 'GHS',
    ]);
    Http::fake(['*/transaction/charge_authorization' => Http::response([
        'status' => true,
        'data' => ['reference' => 'ref_abandoned', 'status' => 'abandoned', 'gateway_response' => 'Abandoned'],
    ], 200)]);

    $transaction = ChargeOrganizationForModelAction::new()->execute(TransactionDTO::new()->fromArray([
        'user' => $member,
        'for' => $therapy,
        'organization' => $organization,
    ]));

    expect($transaction->status)->toBe(TransactionStatusEnum::abandoned->value);
});

// Paystack's real API returns several non-terminal statuses too (e.g. "processing", "queued") --
// mirrors VerifyPaystackTransactionAction's own handling of this same case: leave the transaction
// pending rather than collapsing an unrecognized status into a terminal one.
test('an unrecognized status leaves the transaction pending for a later webhook to resolve', function () {
    [$organization, , $therapy, $member] = anOrgWithCounsellorAndInstrument([
        'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
        'amount' => 5000,
        'currency' => 'GHS',
    ]);
    Http::fake(['*/transaction/charge_authorization' => Http::response([
        'status' => true,
        'data' => ['reference' => 'ref_processing', 'status' => 'processing', 'gateway_response' => null],
    ], 200)]);

    $transaction = ChargeOrganizationForModelAction::new()->execute(TransactionDTO::new()->fromArray([
        'user' => $member,
        'for' => $therapy,
        'organization' => $organization,
    ]));

    expect($transaction->status)->toBe(TransactionStatusEnum::pending->value);
});

test('a mismatched reported amount on an otherwise-successful charge throws rather than silently recording success', function () {
    [$organization, , $therapy, $member] = anOrgWithCounsellorAndInstrument([
        'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
        'amount' => 5000,
        'currency' => 'GHS',
    ]);
    config(['settings.platform_fee_percentage' => 10]);
    Http::fake(['*/transaction/charge_authorization' => Http::response([
        'status' => true,
        'data' => ['reference' => 'ref_mismatch', 'status' => 'success', 'amount' => 1, 'currency' => 'GHS', 'gateway_response' => 'Approved'],
    ], 200)]);

    ChargeOrganizationForModelAction::new()->execute(TransactionDTO::new()->fromArray([
        'user' => $member,
        'for' => $therapy,
        'organization' => $organization,
    ]));
})->throws(TransactionException::class);

test('does not generate a counsellor earning -- that split is TT-7.3b-d\'s job', function () {
    [$organization, , $therapy, $member] = anOrgWithCounsellorAndInstrument([
        'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
        'amount' => 5000,
        'currency' => 'GHS',
    ]);
    config(['settings.platform_fee_percentage' => 10]);
    Http::fake(['*/transaction/charge_authorization' => Http::response([
        'status' => true,
        'data' => ['reference' => 'ref_no_earning', 'status' => 'success', 'amount' => 6000, 'currency' => 'GHS', 'gateway_response' => 'Approved'],
    ], 200)]);

    ChargeOrganizationForModelAction::new()->execute(TransactionDTO::new()->fromArray([
        'user' => $member,
        'for' => $therapy,
        'organization' => $organization,
    ]));

    $this->assertDatabaseCount('counsellor_earnings', 0);
});
