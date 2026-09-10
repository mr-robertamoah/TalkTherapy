<?php

use App\Models\GroupTherapy;
use App\Models\Refund;
use App\Models\Request;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;

// TT-7.7b/SCRUM-250: the real HTTP endpoint a client hits to ask for a refund.

function aSuccessfulTransactionOwnedBy(User $client): Transaction
{
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id]);

    return Transaction::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'user_id' => $client->id,
        'status' => 'SUCCESS',
    ]);
}

function aSuccessfulGroupTherapyTransactionOwnedBy(User $member): Transaction
{
    $groupTherapy = GroupTherapy::factory()->create();

    return Transaction::factory()->create([
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
        'user_id' => $member->id,
        'status' => 'SUCCESS',
    ]);
}

test('the owning client can request a refund via the real HTTP endpoint', function () {
    $client = User::factory()->create();
    $transaction = aSuccessfulTransactionOwnedBy($client);

    $this->actingAs($client);

    $response = $this->postJson(route('transactions.refund_request.store', ['transactionId' => $transaction->id]), [
        'reason' => 'The counsellor never joined the scheduled session.',
    ]);

    $response->assertOk()->assertJson(['refundRequestStatus' => 'PENDING']);
    expect(Request::where('type', 'REFUND_REQUEST')->count())->toBe(1);
});

test('a user who does not own the transaction gets a 403 via the real HTTP endpoint', function () {
    $owner = User::factory()->create();
    $transaction = aSuccessfulTransactionOwnedBy($owner);
    $outsider = User::factory()->create();

    $this->actingAs($outsider);

    $response = $this->postJson(route('transactions.refund_request.store', ['transactionId' => $transaction->id]), [
        'reason' => 'Trying to refund someone else\'s transaction.',
    ]);

    $response->assertStatus(403);
    expect(Request::count())->toBe(0);
});

// Security-engineer finding: a nonexistent transactionId must return the SAME status as an
// existing-but-not-owned one (403), not a distinguishable 422 -- otherwise the response code
// itself becomes an existence oracle for enumerating transaction ids.
test('a nonexistent transaction id returns the same 403 as one that exists but is not owned', function () {
    $client = User::factory()->create();

    $this->actingAs($client);

    $response = $this->postJson(route('transactions.refund_request.store', ['transactionId' => 999999]), [
        'reason' => 'Guessing a transaction id that does not exist.',
    ]);

    $response->assertStatus(403);
});

test('a missing reason fails validation with a 422', function () {
    $client = User::factory()->create();
    $transaction = aSuccessfulTransactionOwnedBy($client);

    $this->actingAs($client);

    $response = $this->postJson(route('transactions.refund_request.store', ['transactionId' => $transaction->id]), []);

    $response->assertStatus(422);
});

test('an already-refunded transaction returns a 422 rather than a duplicate request', function () {
    $client = User::factory()->create();
    $transaction = aSuccessfulTransactionOwnedBy($client);
    Refund::factory()->create(['transaction_id' => $transaction->id, 'status' => 'SUCCESS']);

    $this->actingAs($client);

    $response = $this->postJson(route('transactions.refund_request.store', ['transactionId' => $transaction->id]), [
        'reason' => 'Please refund this again.',
    ]);

    $response->assertStatus(422);
    expect(Request::count())->toBe(0);
});

// TT-7.4d-c/SCRUM-260: confirms the ticket's own premise -- RequestRefundAction was already
// transaction/user-scoped, not model-scoped, so a GroupTherapy member's own transaction is
// refundable through this same endpoint with no backend change.
test('a group therapy member can request a refund for their own transaction via the real HTTP endpoint', function () {
    $member = User::factory()->create();
    $transaction = aSuccessfulGroupTherapyTransactionOwnedBy($member);

    $this->actingAs($member);

    $response = $this->postJson(route('transactions.refund_request.store', ['transactionId' => $transaction->id]), [
        'reason' => 'Could not attend the group session after all.',
    ]);

    $response->assertOk()->assertJson(['refundRequestStatus' => 'PENDING']);
    expect(Request::where('type', 'REFUND_REQUEST')->count())->toBe(1);
});

// The same 403 co-ownership guard applies regardless of the payable's type -- one group member
// must never be able to request a refund for a DIFFERENT member's own transaction.
test('a different group therapy member gets a 403 requesting a refund for someone else\'s transaction', function () {
    $payer = User::factory()->create();
    $transaction = aSuccessfulGroupTherapyTransactionOwnedBy($payer);
    $coMember = User::factory()->create();

    $this->actingAs($coMember);

    $response = $this->postJson(route('transactions.refund_request.store', ['transactionId' => $transaction->id]), [
        'reason' => 'Trying to refund a co-member\'s transaction.',
    ]);

    $response->assertStatus(403);
    expect(Request::count())->toBe(0);
});

test('a guest cannot reach the endpoint at all', function () {
    $client = User::factory()->create();
    $transaction = aSuccessfulTransactionOwnedBy($client);

    $response = $this->postJson(route('transactions.refund_request.store', ['transactionId' => $transaction->id]), [
        'reason' => 'Trying without logging in.',
    ]);

    $response->assertStatus(401);
});
