<?php

use App\Enums\TransactionStatusEnum;
use App\Models\GroupTherapy;
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
