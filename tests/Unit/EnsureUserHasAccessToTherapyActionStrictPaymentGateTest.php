<?php

use App\Actions\Therapy\EnsureUserHasAccessToTherapyAction;
use App\DTOs\GetTherapyDTO;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Enums\OrganizationMemberBillingModeEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Exceptions\OrganizationBillingSuspendedException;
use App\Exceptions\PaymentRequiredException;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\Guardianship;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\OrganizationMember;
use App\Models\OrganizationMemberBillingConfig;
use App\Models\PaymentAccessGrant;
use App\Models\Request;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;

// SCRUM-219/TT-7.5a: page-load enforcement for the PER_THERAPY strict payment gate.

function strictGatedPaidTherapy(array $overrides = []): Therapy
{
    return Therapy::factory()->create(array_merge([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'public' => false,
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60, 'strictPaymentGate' => true],
    ], $overrides));
}

test('a client with no grant and no successful transaction is denied access with PaymentRequiredException', function () {
    $client = User::factory()->create();
    $therapy = strictGatedPaidTherapy(['addedby_id' => $client->id]);

    $dto = GetTherapyDTO::new()->fromArray(['user' => $client, 'therapy' => $therapy]);

    expect(fn () => EnsureUserHasAccessToTherapyAction::new()->execute($dto))
        ->toThrow(PaymentRequiredException::class);

    $this->assertDatabaseCount('payment_access_grants', 0);
});

test('a client with a successful transaction is granted access and a grant is persisted', function () {
    $client = User::factory()->create();
    $therapy = strictGatedPaidTherapy(['addedby_id' => $client->id]);
    Transaction::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'user_id' => $client->id,
        'status' => 'SUCCESS',
    ]);

    $dto = GetTherapyDTO::new()->fromArray(['user' => $client, 'therapy' => $therapy]);

    expect(fn () => EnsureUserHasAccessToTherapyAction::new()->execute($dto))
        ->not->toThrow(PaymentRequiredException::class);

    $this->assertDatabaseHas('payment_access_grants', [
        'user_id' => $client->id,
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
    ]);
});

test('a client with an existing grant keeps access even after the underlying transaction is later marked non-successful', function () {
    $client = User::factory()->create();
    $therapy = strictGatedPaidTherapy(['addedby_id' => $client->id]);
    $transaction = Transaction::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'user_id' => $client->id,
        'status' => 'SUCCESS',
    ]);
    PaymentAccessGrant::factory()->create([
        'user_id' => $client->id,
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'transaction_id' => $transaction->id,
    ]);
    $transaction->update(['status' => 'FAILED']);

    $dto = GetTherapyDTO::new()->fromArray(['user' => $client, 'therapy' => $therapy]);

    expect(fn () => EnsureUserHasAccessToTherapyAction::new()->execute($dto))
        ->not->toThrow(PaymentRequiredException::class);
});

// TT-7.3b-f2/SCRUM-238: proves the page-load call site lets the new exception propagate rather
// than swallowing it or misclassifying it as a PaymentRequiredException (the two must stay
// distinguishable -- a controller catch for one must not accidentally catch the other, since
// PaymentRequiredException drives the client toward a Pay Now flow this case has no use for).
test('a retainer-covered client whose organization is billing-suspended is denied with OrganizationBillingSuspendedException, not PaymentRequiredException', function () {
    $client = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = strictGatedPaidTherapy(['addedby_id' => $client->id, 'counsellor_id' => $counsellor->id]);

    $organization = Organization::factory()->create(['is_consumer' => true, 'verified_at' => now()]);
    OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellor->id,
        'status' => OrganizationCounsellorStatusEnum::active->value,
    ]);
    $member = OrganizationMember::factory()->create(['organization_id' => $organization->id, 'user_id' => $client->id]);
    OrganizationMemberBillingConfig::factory()->create([
        'organization_member_id' => $member->id,
        'mode' => OrganizationMemberBillingModeEnum::retainer->value,
    ]);
    $organization->suspendBilling('Retainer invoice settlement failed.');

    $dto = GetTherapyDTO::new()->fromArray(['user' => $client, 'therapy' => $therapy]);

    expect(fn () => EnsureUserHasAccessToTherapyAction::new()->execute($dto))
        ->toThrow(OrganizationBillingSuspendedException::class);
});

test('the counsellor of a strict-gated therapy is never subject to the payment gate', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = strictGatedPaidTherapy(['counsellor_id' => $counsellor->id]);

    $dto = GetTherapyDTO::new()->fromArray(['user' => $counsellorUser, 'therapy' => $therapy]);

    expect(fn () => EnsureUserHasAccessToTherapyAction::new()->execute($dto))
        ->not->toThrow(PaymentRequiredException::class);

    $this->assertDatabaseCount('payment_access_grants', 0);
});

test('an admin is never subject to the payment gate on a strict-gated therapy', function () {
    $admin = User::factory()->has(Administrator::factory())->create();
    $therapy = strictGatedPaidTherapy();

    $dto = GetTherapyDTO::new()->fromArray(['user' => $admin, 'therapy' => $therapy]);

    expect(fn () => EnsureUserHasAccessToTherapyAction::new()->execute($dto))
        ->not->toThrow(PaymentRequiredException::class);
});

test('a public strict-gated therapy is still visible to a non-participant, unaffected by the payment gate', function () {
    $unrelatedUser = User::factory()->create();
    $therapy = strictGatedPaidTherapy(['public' => true]);

    $dto = GetTherapyDTO::new()->fromArray(['user' => $unrelatedUser, 'therapy' => $therapy]);

    expect(fn () => EnsureUserHasAccessToTherapyAction::new()->execute($dto))
        ->not->toThrow(PaymentRequiredException::class);
});

test('a trust-based (non-strict) paid therapy is unaffected -- the client is granted access with no transaction at all', function () {
    $client = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'public' => false,
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60, 'strictPaymentGate' => false],
    ]);

    $dto = GetTherapyDTO::new()->fromArray(['user' => $client, 'therapy' => $therapy]);

    expect(fn () => EnsureUserHasAccessToTherapyAction::new()->execute($dto))
        ->not->toThrow(PaymentRequiredException::class);

    $this->assertDatabaseCount('payment_access_grants', 0);
});

test('a strict-gated PER_SESSION therapy is not blocked at the page-load level -- that is SCRUM-220 scope', function () {
    $client = User::factory()->create();
    $therapy = strictGatedPaidTherapy([
        'addedby_id' => $client->id,
        'payment_data' => ['per' => 'PER_SESSION', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60, 'strictPaymentGate' => true],
    ]);

    $dto = GetTherapyDTO::new()->fromArray(['user' => $client, 'therapy' => $therapy]);

    expect(fn () => EnsureUserHasAccessToTherapyAction::new()->execute($dto))
        ->not->toThrow(PaymentRequiredException::class);

    $this->assertDatabaseCount('payment_access_grants', 0);
});

test('a guardian of the client is never subject to the payment gate on a strict-gated therapy', function () {
    $client = User::factory()->create();
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $client->id]);
    $therapy = strictGatedPaidTherapy(['addedby_id' => $client->id]);

    $dto = GetTherapyDTO::new()->fromArray(['user' => $guardian, 'therapy' => $therapy]);

    expect(fn () => EnsureUserHasAccessToTherapyAction::new()->execute($dto))
        ->not->toThrow(PaymentRequiredException::class);

    $this->assertDatabaseCount('payment_access_grants', 0);
});

test('a counsellor with only a pending request for the therapy is never subject to the payment gate', function () {
    $client = User::factory()->create();
    $therapy = strictGatedPaidTherapy(['addedby_id' => $client->id]);
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);

    Request::query()->forceCreate([
        'data' => [],
        'type' => RequestTypeEnum::therapy->value,
        'status' => RequestStatusEnum::pending->value,
        'from_type' => Counsellor::class,
        'from_id' => $counsellor->id,
        'to_type' => User::class,
        'to_id' => $client->id,
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
    ]);

    $dto = GetTherapyDTO::new()->fromArray(['user' => $counsellorUser, 'therapy' => $therapy]);

    expect(fn () => EnsureUserHasAccessToTherapyAction::new()->execute($dto))
        ->not->toThrow(PaymentRequiredException::class);

    $this->assertDatabaseCount('payment_access_grants', 0);
});

test('a grant or successful transaction belonging to a different therapy or a different user never satisfies the gate', function () {
    $client = User::factory()->create();
    $therapy = strictGatedPaidTherapy(['addedby_id' => $client->id]);

    $otherTherapy = strictGatedPaidTherapy();
    $otherUser = User::factory()->create();

    // Decoy grant for the same therapy but a different user.
    PaymentAccessGrant::factory()->create([
        'user_id' => $otherUser->id,
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
    ]);
    // Decoy grant for the same client but a different therapy.
    PaymentAccessGrant::factory()->create([
        'user_id' => $client->id,
        'for_type' => Therapy::class,
        'for_id' => $otherTherapy->id,
    ]);
    // Decoy successful transaction for the same therapy but a different user.
    Transaction::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'user_id' => $otherUser->id,
        'status' => 'SUCCESS',
    ]);

    $dto = GetTherapyDTO::new()->fromArray(['user' => $client, 'therapy' => $therapy]);

    expect(fn () => EnsureUserHasAccessToTherapyAction::new()->execute($dto))
        ->toThrow(PaymentRequiredException::class);
});
