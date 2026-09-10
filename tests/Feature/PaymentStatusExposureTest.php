<?php

use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Enums\RefundStatusEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\TransactionStatusEnum;
use App\Models\GroupTherapy;
use App\Models\Refund;
use App\Models\Session as TherapySession;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;

// SCRUM-156 (TT-7.4a): payment-status exposure plumbing -- no Resource exposed transaction
// status before this, and the `transactionStatus` flash value TransactionController::callback
// sets was never read back down. These tests cover both halves.

test('TherapyResource exposes paymentStatus from the latest transaction, not an older one', function () {
    $user = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $user->id,
    ]);

    Transaction::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'status' => TransactionStatusEnum::failed->value,
        'created_at' => now()->subDay(),
    ]);
    Transaction::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'status' => TransactionStatusEnum::success->value,
        'created_at' => now(),
    ]);

    $response = $this->actingAs($user)->get(route('therapies.get', ['therapyId' => $therapy->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('therapy.paymentStatus', TransactionStatusEnum::success->value)
    );
});

// latestOfMany() defaults to ordering by 'id', not 'created_at' -- this creates the
// semantically-latest (by created_at) row *first* so it gets the lower id, proving the relation
// is explicitly ordering by created_at rather than happening to agree with insertion order.
test('paymentStatus reflects the most recently created transaction even when it was inserted first', function () {
    $user = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $user->id,
    ]);

    Transaction::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'status' => TransactionStatusEnum::success->value,
        'created_at' => now(),
    ]);
    Transaction::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'status' => TransactionStatusEnum::failed->value,
        'created_at' => now()->subDay(),
    ]);

    $response = $this->actingAs($user)->get(route('therapies.get', ['therapyId' => $therapy->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('therapy.paymentStatus', TransactionStatusEnum::success->value)
    );
});

test('GroupTherapyResource exposes paymentStatus from the latest transaction', function () {
    $user = User::factory()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $user->id,
    ]);

    Transaction::factory()->create([
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
        'status' => TransactionStatusEnum::success->value,
    ]);

    $response = $this->actingAs($user)->get(route('group.therapies.get', ['groupTherapyId' => $groupTherapy->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('therapy.paymentStatus', TransactionStatusEnum::success->value)
    );
});

// TT-7.4d-a/SCRUM-258: the viewer-scoped counterpart to the model-wide `paymentStatus` above --
// needed once a GroupTherapy can have more than one member's own Transaction, where the model-wide
// field alone can no longer answer "have I, specifically, paid."
test('GroupTherapyResource exposes viewerPaymentStatus/transactionId scoped to the viewer\'s own transaction', function () {
    $user = User::factory()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $user->id,
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 100, 'currency' => 'GHS'],
    ]);
    $transaction = Transaction::factory()->create([
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
        'user_id' => $user->id,
        'status' => TransactionStatusEnum::success->value,
    ]);

    $response = $this->actingAs($user)->get(route('group.therapies.get', ['groupTherapyId' => $groupTherapy->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('therapy.viewerPaymentStatus', TransactionStatusEnum::success->value)
        ->where('therapy.transactionId', $transaction->id)
    );
});

// The core privacy regression this ticket exists to close: before TT-7.4d-a, the only per-viewer
// field GroupTherapyResource had was none at all -- any new field here MUST be scoped from day
// one, not just the model-wide `paymentStatus` a co-participant could already see.
test('GroupTherapyResource never leaks one member\'s transaction to a different member viewing the same group', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = $counsellorUser->counsellor()->create(['email' => fake()->unique()->safeEmail(), 'about' => 'test']);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $counsellorUser->id,
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 100, 'currency' => 'GHS'],
        'public' => true,
    ]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);
    $memberA = User::factory()->create();
    $memberB = User::factory()->create();
    $groupTherapy->users()->attach($memberA->id, ['background_story' => 'test', 'anonymous' => false]);
    $groupTherapy->users()->attach($memberB->id, ['background_story' => 'test', 'anonymous' => false]);

    $transactionA = Transaction::factory()->create([
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
        'user_id' => $memberA->id,
        'status' => TransactionStatusEnum::success->value,
    ]);
    // Security-engineer finding: refundRequestStatus is derived from the same $viewerTransaction
    // as viewerPaymentStatus/transactionId, so a null $viewerTransaction transitively covers it --
    // but an explicit assertion (rather than relying on that being implicit) makes the coverage
    // self-evident, per the review's own recommendation.
    $memberA->sentRequests()->create([
        'data' => ['reason' => 'test'],
        'type' => RequestTypeEnum::refund->value,
        'status' => RequestStatusEnum::pending->value,
    ])->for()->associate($transactionA)->save();

    // Member B has never paid -- must see their OWN (null) status, never A's SUCCESS or A's
    // refund-request status, even though the model-wide paymentStatus below (a pre-existing,
    // deliberately unscoped field) does reflect A's transaction.
    $response = $this->actingAs($memberB)->get(route('group.therapies.get', ['groupTherapyId' => $groupTherapy->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('therapy.paymentStatus', TransactionStatusEnum::success->value)
        ->where('therapy.viewerPaymentStatus', null)
        ->where('therapy.transactionId', null)
        ->where('therapy.refundRequestStatus', null)
    );
});

test('a therapy with no transactions exposes a null paymentStatus', function () {
    $user = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->get(route('therapies.get', ['therapyId' => $therapy->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('therapy.paymentStatus', null)
    );
});

test('SessionResource exposes paymentStatus from its own latest transaction', function () {
    $user = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $user->id,
    ]);
    $session = TherapySession::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
    ]);

    Transaction::factory()->create([
        'for_type' => TherapySession::class,
        'for_id' => $session->id,
        'status' => TransactionStatusEnum::pending->value,
    ]);

    $response = $this->actingAs($user)->get(route('therapies.get', ['therapyId' => $therapy->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('recentSessions.0.paymentStatus', TransactionStatusEnum::pending->value)
    );
});

// TT-7.7e/SCRUM-253: paymentStatus itself never flips off SUCCESS on refund (refunds live in
// their own table -- see TT-7.7a's decision-log entry), so refundStatus is a separate, additive
// field the frontend checks to show "Refunded" instead of a now-stale "Paid".
test('TherapyResource exposes refundStatus SUCCESS once a refund has succeeded', function () {
    $user = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $user->id,
    ]);
    $transaction = Transaction::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'status' => TransactionStatusEnum::success->value,
    ]);
    Refund::factory()->create([
        'transaction_id' => $transaction->id,
        'status' => RefundStatusEnum::success->value,
    ]);

    $response = $this->actingAs($user)->get(route('therapies.get', ['therapyId' => $therapy->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('therapy.refundStatus', RefundStatusEnum::success->value)
    );
});

test('TherapyResource exposes a null refundStatus when no refund exists', function () {
    $user = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $user->id,
    ]);
    Transaction::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'status' => TransactionStatusEnum::success->value,
    ]);

    $response = $this->actingAs($user)->get(route('therapies.get', ['therapyId' => $therapy->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('therapy.refundStatus', null)
    );
});

// A refund still pending/processing (not yet successful) must not prematurely flip the UI to
// "Refunded" -- only a SUCCESS refund is a completed outcome.
test('TherapyResource does not expose refundStatus for a pending refund', function () {
    $user = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $user->id,
    ]);
    $transaction = Transaction::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'status' => TransactionStatusEnum::success->value,
    ]);
    Refund::factory()->create([
        'transaction_id' => $transaction->id,
        'status' => RefundStatusEnum::pending->value,
    ]);

    $response = $this->actingAs($user)->get(route('therapies.get', ['therapyId' => $therapy->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('therapy.refundStatus', null)
    );
});

test('SessionResource exposes refundStatus SUCCESS once a refund has succeeded', function () {
    $user = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $user->id,
    ]);
    $session = TherapySession::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
    ]);
    $transaction = Transaction::factory()->create([
        'for_type' => TherapySession::class,
        'for_id' => $session->id,
        'status' => TransactionStatusEnum::success->value,
    ]);
    Refund::factory()->create([
        'transaction_id' => $transaction->id,
        'status' => RefundStatusEnum::success->value,
    ]);

    $response = $this->actingAs($user)->get(route('therapies.get', ['therapyId' => $therapy->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('recentSessions.0.refundStatus', RefundStatusEnum::success->value)
    );
});

// Security-engineer finding (TT-7.7e/SCRUM-253): SessionResource is shared by GroupTherapy
// sessions, where latestTransaction can belong to a DIFFERENT member entirely -- unlike the
// individual-Therapy case (single payer, safe to expose unscoped), a group co-participant must
// never see whether some OTHER member's payment was refunded.
test('SessionResource never exposes refundStatus for a GroupTherapy session, even when a member has a successful refund', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = $counsellorUser->counsellor()->create(['email' => fake()->unique()->safeEmail(), 'about' => 'test']);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $counsellorUser->id,
        'public' => true,
    ]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);
    $member = User::factory()->create();
    $session = TherapySession::factory()->create([
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
    ]);
    $transaction = Transaction::factory()->create([
        'for_type' => TherapySession::class,
        'for_id' => $session->id,
        'user_id' => $member->id,
        'status' => TransactionStatusEnum::success->value,
    ]);
    Refund::factory()->create([
        'transaction_id' => $transaction->id,
        'status' => RefundStatusEnum::success->value,
    ]);

    // Viewed by the counsellor -- never the payer, so this is exactly the "co-participant" case
    // the fix protects.
    $response = $this->actingAs($counsellorUser)->get(route('group.therapies.get', ['groupTherapyId' => $groupTherapy->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('recentSessions.0.refundStatus', null)
    );
});

test('the transactionStatus flash value is passed through as an Inertia prop on the therapy page', function () {
    $user = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $user->id,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['transactionStatus' => TransactionStatusEnum::success->value])
        ->get(route('therapies.get', ['therapyId' => $therapy->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('transactionStatus', TransactionStatusEnum::success->value)
    );
});

test('the transactionStatus prop is null when nothing was flashed', function () {
    $user = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->get(route('therapies.get', ['therapyId' => $therapy->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('transactionStatus', null)
    );
});
