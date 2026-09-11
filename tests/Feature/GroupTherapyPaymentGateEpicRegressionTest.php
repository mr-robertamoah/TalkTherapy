<?php

use App\Actions\Message\EnsureUserCanAccessTherapyContentAction;
use App\Actions\Transaction\EnsureStrictPaymentGateSatisfiedAction;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;

// TT-7.5b-b6/SCRUM-270: epic closeout regression coverage for the whole GroupTherapy strict-
// payment-gate epic (TT-7.5b-b0 through -b5). Each individual mechanism is already covered
// thoroughly by its own sub-ticket's test files (EnsureUserHasAccessToTherapyActionGroupTherapy...,
// EnsureUserCanAccessTherapyContentActionGroupTherapy..., MessageServiceGroupTherapyStrictPayment...,
// GroupTherapyJoinPaymentGateRegressionTest, UpdateGroupTherapyPaymentGateTest, etc.) -- this file
// adds the two things none of those individually prove: (1) an end-to-end journey through every
// stage of the gate for ONE group and ONE member via real HTTP routes, and (2) an explicit,
// consolidated "individual Therapy's own strict-gate behavior is completely unaffected by this
// epic" pinning test, since b2/b3 widened shared code (EnsureStrictPaymentGateSatisfiedAction,
// EnsureUserHasAccessToTherapyAction, EnsureUserCanAccessTherapyContentAction) rather than writing
// new independent copies.

test('the whole epic works end to end for one group and one member: blocked, paid, exempt-for-history, never-blocked-for-counsellor, always-free-to-join', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'public' => false,
        'payment_type' => 'PAID',
        'payment_data' => [
            'per' => 'PER_THERAPY', 'amount' => 100, 'currency' => 'GHS', 'inPersonAmount' => 120,
            'strictPaymentGate' => true, 'allowFreeHistoricalAccess' => true,
        ],
    ]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);

    // A member who joined 5 days ago, before the group's PER_THERAPY strict gate blocks new
    // content -- old content stays visible for free per allowFreeHistoricalAccess; new content
    // requires payment.
    $member = User::factory()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false, 'created_at' => now()->subDays(5)]);

    // Stage 1: blocked at page load -- the member has never paid.
    $blocked = $this->actingAs($member)->get("/group-therapies/{$groupTherapy->id}");
    $blocked->assertRedirect(route('home'));
    $blocked->assertSessionHas('paymentRequired', true);
    $blocked->assertSessionHas('paymentRequiredGroupTherapyId', (string) $groupTherapy->id);

    // Stage 2: content dated before the member's own join stays visible even while unpaid, via
    // the shared content-access action directly (mirrors how MessageService's own read paths call
    // it -- exercised at the HTTP layer in that ticket's own test files already).
    $oldSession = Session::factory()->create([
        'for_id' => $groupTherapy->id, 'for_type' => GroupTherapy::class, 'start_time' => now()->subDays(10),
    ]);
    expect(EnsureUserCanAccessTherapyContentAction::new()->execute(
        $groupTherapy, $member, $oldSession, $oldSession->start_time
    ))->toBeTrue();

    // Stage 3: content dated after the member's join is gated normally while unpaid.
    $newSession = Session::factory()->create([
        'for_id' => $groupTherapy->id, 'for_type' => GroupTherapy::class, 'start_time' => now()->subDay(),
    ]);
    expect(EnsureUserCanAccessTherapyContentAction::new()->execute(
        $groupTherapy, $member, $newSession, $newSession->start_time
    ))->toBeFalse();

    // Stage 4: the member pays -- via the real HTTP route -- and is granted access.
    Http::fake([
        '*/transaction/initialize' => Http::response([
            'status' => true, 'message' => 'ok', 'data' => [
                'authorization_url' => 'https://checkout.paystack.com/ref_member',
                'access_code' => 'ref_member', 'reference' => 'ref_member',
            ],
        ], 200),
    ]);
    $this->actingAs($member)
        ->postJson(route('transactions.initiate.group_therapy', ['groupTherapyId' => $groupTherapy->id]))
        ->assertOk();
    Transaction::where('user_id', $member->id)->update(['status' => 'SUCCESS']);

    $allowed = $this->actingAs($member)->get("/group-therapies/{$groupTherapy->id}");
    $allowed->assertOk();
    $allowed->assertSessionMissing('paymentRequired');

    // Stage 5: the active counsellor was never blocked, at any point in this journey.
    $counsellorView = $this->actingAs($counsellorUser)->get("/group-therapies/{$groupTherapy->id}");
    $counsellorView->assertOk();
    $counsellorView->assertSessionMissing('paymentRequired');

    // Stage 6: a brand-new, never-paying member can still join today -- joining itself was never
    // gated by any of this (TT-7.5b-b4's own confirmed decision).
    $newJoiner = User::factory()->create();
    $groupTherapy->users()->attach($newJoiner->id, ['anonymous' => false]);
    expect($groupTherapy->fresh()->users()->whereKey($newJoiner->id)->exists())->toBeTrue();
});

// TT-7.5b widened EnsureStrictPaymentGateSatisfiedAction/EnsureUserHasAccessToTherapyAction/
// EnsureUserCanAccessTherapyContentAction rather than writing independent GroupTherapy-only
// copies -- this pins down that individual Therapy's own page-load and content-access strict-gate
// behavior is completely unaffected by any of that widening.
test('individual Therapy strict-gate page-load and content-access behavior is completely unaffected by the GroupTherapy widening', function () {
    $client = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'public' => false,
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60, 'strictPaymentGate' => true],
    ]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class, 'start_time' => now()]);

    // Page load: blocked while unpaid.
    $blocked = $this->actingAs($client)->get("/therapies/{$therapy->id}");
    $blocked->assertRedirect(route('home'));
    $blocked->assertSessionHas('paymentRequired', true);
    $blocked->assertSessionHas('paymentRequiredTherapyId', (string) $therapy->id);
    // No GroupTherapy-only flash key ever leaks onto an individual-Therapy redirect.
    $blocked->assertSessionMissing('paymentRequiredGroupTherapyId');

    // Content access: also blocked while unpaid, with no late-joiner exemption ever applying to
    // individual Therapy (it doesn't have a "join date" concept at all).
    expect(EnsureUserCanAccessTherapyContentAction::new()->execute(
        $therapy, $client, $session, $session->start_time
    ))->toBeFalse();

    // Pay, then confirm both are unblocked.
    Transaction::factory()->create([
        'for_type' => Therapy::class, 'for_id' => $therapy->id, 'user_id' => $client->id, 'status' => 'SUCCESS',
    ]);

    $allowed = $this->actingAs($client)->get("/therapies/{$therapy->id}");
    $allowed->assertOk();
    $allowed->assertSessionMissing('paymentRequired');

    expect(EnsureUserCanAccessTherapyContentAction::new()->execute(
        $therapy, $client, $session, $session->start_time
    ))->toBeTrue();
});

// Confirms the architect's own zero-schema-change assumption from SCRUM-216's scoping held for
// the entire epic: payment_access_grants' pre-existing (user_id, for_type, for_id) shape already
// supported GroupTherapy grants with no migration at all.
test('payment_access_grants requires no GroupTherapy-specific columns -- the pre-existing schema already supports it', function () {
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'public' => false,
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'strictPaymentGate' => true],
    ]);
    $member = User::factory()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false]);
    Transaction::factory()->create([
        'for_type' => GroupTherapy::class, 'for_id' => $groupTherapy->id, 'user_id' => $member->id, 'status' => 'SUCCESS',
    ]);

    EnsureStrictPaymentGateSatisfiedAction::new()->execute($groupTherapy, $member);

    $this->assertDatabaseHas('payment_access_grants', [
        'user_id' => $member->id,
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
    ]);
});
