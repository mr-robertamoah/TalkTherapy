<?php

use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\User;

// TT-7.5b-b1/SCRUM-265: mirrors UpdateStrictPaymentGateTest.php's own precedent exactly, adapted
// for GroupTherapy's multi-counsellor authorization (TT-7.5b-b0) instead of TT-7.5a's
// single-counsellor one. The core regression this proves: an ACTIVE counsellor who is NOT the
// group's own addedby (the normal case) can still reach this dedicated endpoint, bypassing
// EnsureCanUpdateTherapyAction's addedby-only gate that the general group.therapies.update
// endpoint is stuck with.

function anActiveCounsellorGroupTherapy(): array
{
    $client = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'payment_type' => 'PAID',
        'public' => true,
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 100, 'currency' => 'GHS', 'shareEqually' => false, 'sharePercentage' => 70, 'strictPaymentGate' => false, 'allowFreeHistoricalAccess' => true],
    ]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);

    return [$client, $counsellorUser, $groupTherapy];
}

test('an active counsellor (not the group addedby) can toggle strictPaymentGate via the dedicated endpoint', function () {
    [$client, $counsellorUser, $groupTherapy] = anActiveCounsellorGroupTherapy();

    $response = $this->actingAs($counsellorUser)
        ->patch(route('group.therapies.payment_gate.update', ['groupTherapyId' => $groupTherapy->id]), [
            'strictPaymentGate' => true,
        ]);

    $response->assertSessionHasNoErrors();
    expect($groupTherapy->fresh()->payment_data['strictPaymentGate'])->toBeTrue();
});

test('an active counsellor can toggle allowFreeHistoricalAccess independently, leaving strictPaymentGate untouched', function () {
    [$client, $counsellorUser, $groupTherapy] = anActiveCounsellorGroupTherapy();

    $response = $this->actingAs($counsellorUser)
        ->patch(route('group.therapies.payment_gate.update', ['groupTherapyId' => $groupTherapy->id]), [
            'allowFreeHistoricalAccess' => false,
        ]);

    $response->assertSessionHasNoErrors();
    expect($groupTherapy->fresh()->payment_data['allowFreeHistoricalAccess'])->toBeFalse()
        ->and($groupTherapy->fresh()->payment_data['strictPaymentGate'])->toBeFalse();
});

test('the group\'s own client (addedby) is refused on the dedicated endpoint', function () {
    [$client, $counsellorUser, $groupTherapy] = anActiveCounsellorGroupTherapy();

    $response = $this->actingAs($client)
        ->patch(route('group.therapies.payment_gate.update', ['groupTherapyId' => $groupTherapy->id]), [
            'strictPaymentGate' => true,
        ]);

    $response->assertSessionHasErrors('alert');
    expect($groupTherapy->fresh()->payment_data['strictPaymentGate'])->toBeFalse();
});

test('an admin can toggle the payment gate settings via the dedicated endpoint', function () {
    [$client, $counsellorUser, $groupTherapy] = anActiveCounsellorGroupTherapy();
    $admin = User::factory()->has(Administrator::factory())->create();

    $response = $this->actingAs($admin)
        ->patch(route('group.therapies.payment_gate.update', ['groupTherapyId' => $groupTherapy->id]), [
            'strictPaymentGate' => true,
        ]);

    $response->assertSessionHasNoErrors();
    expect($groupTherapy->fresh()->payment_data['strictPaymentGate'])->toBeTrue();
});

test('a counsellor unrelated to this group cannot toggle its payment gate settings', function () {
    [$client, $counsellorUser, $groupTherapy] = anActiveCounsellorGroupTherapy();
    $outsiderCounsellorUser = User::factory()->create();
    Counsellor::factory()->create(['user_id' => $outsiderCounsellorUser->id]);

    $response = $this->actingAs($outsiderCounsellorUser)
        ->patch(route('group.therapies.payment_gate.update', ['groupTherapyId' => $groupTherapy->id]), [
            'strictPaymentGate' => true,
        ]);

    $response->assertSessionHasErrors('alert');
    expect($groupTherapy->fresh()->payment_data['strictPaymentGate'])->toBeFalse();
});

test('at least one payment gate field is required', function () {
    [$client, $counsellorUser, $groupTherapy] = anActiveCounsellorGroupTherapy();

    $response = $this->actingAs($counsellorUser)
        ->patch(route('group.therapies.payment_gate.update', ['groupTherapyId' => $groupTherapy->id]), []);

    $response->assertSessionHasErrors('alert');
});

// Security-review precedent (mirrors TT-7.5a's own SCRUM-221 finding): a plain group member with
// no counsellor relationship at all must never pass, regardless of anything else about the group.
test('a plain group member cannot toggle the payment gate settings', function () {
    [$client, $counsellorUser, $groupTherapy] = anActiveCounsellorGroupTherapy();
    $member = User::factory()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false]);

    $response = $this->actingAs($member)
        ->patch(route('group.therapies.payment_gate.update', ['groupTherapyId' => $groupTherapy->id]), [
            'strictPaymentGate' => true,
        ]);

    $response->assertSessionHasErrors('alert');
    expect($groupTherapy->fresh()->payment_data['strictPaymentGate'])->toBeFalse();
});

// TT-7.5b-b1/SCRUM-265's own defense-in-depth: the general update endpoint must not let the
// group's client sneak a gate change through it either, even though they DO pass
// EnsureCanUpdateTherapyAction (they're the addedby).
test('the client cannot sneak strictPaymentGate through the general group therapy update endpoint either', function () {
    [$client, $counsellorUser, $groupTherapy] = anActiveCounsellorGroupTherapy();

    $response = $this->actingAs($client)
        ->patch(route('group.therapies.update', ['groupTherapyId' => $groupTherapy->id]), [
            'strictPaymentGate' => true,
        ]);

    $response->assertSessionHasErrors('alert');
    expect($groupTherapy->fresh()->payment_data['strictPaymentGate'])->toBeFalse();
});

// The flip side: a counsellor who created the group directly (addedby, so they pass
// EnsureCanUpdateTherapyAction too) can change the gate via the general update endpoint, since
// they're also an active counsellor per EnsureCanSetGroupTherapyPaymentGateAction.
test('a counsellor who created the group directly can change strictPaymentGate via the general update endpoint', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => Counsellor::class,
        'addedby_id' => $counsellor->id,
        'payment_type' => 'PAID',
        'public' => true,
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 100, 'currency' => 'GHS', 'shareEqually' => true, 'strictPaymentGate' => false, 'allowFreeHistoricalAccess' => true],
    ]);

    $response = $this->actingAs($counsellorUser)
        ->patch(route('group.therapies.update', ['groupTherapyId' => $groupTherapy->id]), [
            'strictPaymentGate' => true,
        ]);

    $response->assertSessionHasNoErrors();
    expect($groupTherapy->fresh()->payment_data['strictPaymentGate'])->toBeTrue();
});
