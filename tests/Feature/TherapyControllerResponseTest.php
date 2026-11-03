<?php

use App\Exceptions\TherapyException;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\Therapy;
use App\Models\User;

// SCRUM-379: TherapyController's update/strict-gate/end/delete endpoints used to answer every caller
// with a redirect -- including JSON callers of the api.therapies.* routes. A script now gets JSON; an
// Inertia form (UpdateIndividualTherapyFormModal, UnifiedTherapy, TherapyPaymentDetails) still gets the
// redirect, and a refusal still arrives as errors.alert.

function therapyResponseOwnedTherapy(): array
{
    $owner = User::factory()->adult()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $owner->id, 'name' => 'Original']);

    return [$owner, $therapy];
}

function therapyResponseInertiaHeaders(): array
{
    return ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html, application/xhtml+xml'];
}

test('a JSON update gets JSON with the updated therapy', function (string $routeName) {
    [$owner, $therapy] = therapyResponseOwnedTherapy();

    $response = $this->actingAs($owner)->patchJson(route($routeName, ['therapyId' => $therapy->id]), ['name' => 'Renamed']);

    $response->assertOk()
        ->assertJsonPath('status', true)
        ->assertJsonPath('therapy.id', $therapy->id)
        ->assertJsonPath('therapy.name', 'Renamed');
    expect($therapy->fresh()->name)->toBe('Renamed');
})->with(['api.therapies.update', 'therapies.update']);

test('a refused JSON update gets the 422 JSON message, not a redirect', function () {
    [, $therapy] = therapyResponseOwnedTherapy();

    $response = $this->actingAs(User::factory()->adult()->create())
        ->patchJson(route('api.therapies.update', ['therapyId' => $therapy->id]), ['name' => 'Renamed']);

    $response->assertStatus(422)->assertJson(['message' => TherapyException::NOT_ALLOWED]);
    expect($therapy->fresh()->name)->toBe('Original');
});

test('an Inertia update is still redirected back, with no errors', function () {
    [$owner, $therapy] = therapyResponseOwnedTherapy();

    $response = $this->actingAs($owner)->withHeaders(therapyResponseInertiaHeaders())
        ->from('/therapies/'.$therapy->id)
        ->patch(route('therapies.update', ['therapyId' => $therapy->id]), ['name' => 'Renamed']);

    $response->assertRedirect('/therapies/'.$therapy->id);
    $response->assertSessionHasNoErrors();
    expect($therapy->fresh()->name)->toBe('Renamed');
});

test('a refused Inertia update is still redirected back with the alert', function () {
    [, $therapy] = therapyResponseOwnedTherapy();

    $response = $this->actingAs(User::factory()->adult()->create())->withHeaders(therapyResponseInertiaHeaders())
        ->from('/therapies/'.$therapy->id)
        ->patch(route('therapies.update', ['therapyId' => $therapy->id]), ['name' => 'Renamed']);

    $response->assertRedirect('/therapies/'.$therapy->id);
    $response->assertSessionHasErrors(['alert' => TherapyException::NOT_ALLOWED]);
    expect($therapy->fresh()->name)->toBe('Original');
});

// Ending needs held periodic sessions -- or an admin, which keeps these about the response.
test('a JSON end gets JSON', function () {
    [, $therapy] = therapyResponseOwnedTherapy();

    $this->actingAs(User::factory()->has(Administrator::factory())->create())
        ->postJson(route('api.therapies.end', ['therapyId' => $therapy->id]))
        ->assertOk()->assertExactJson(['status' => true]);
});

test('an Inertia end is still redirected back', function () {
    [, $therapy] = therapyResponseOwnedTherapy();

    $this->actingAs(User::factory()->has(Administrator::factory())->create())
        ->withHeaders(therapyResponseInertiaHeaders())->from('/therapies/'.$therapy->id)
        ->post(route('therapies.end', ['therapyId' => $therapy->id]))
        ->assertRedirect('/therapies/'.$therapy->id);
});

test('a JSON delete gets JSON', function () {
    [$owner, $therapy] = therapyResponseOwnedTherapy();

    $this->actingAs($owner)->deleteJson(route('api.therapies.delete', ['therapyId' => $therapy->id]))
        ->assertOk()->assertExactJson(['status' => true]);
});

test('an Inertia delete is still redirected home', function () {
    [$owner, $therapy] = therapyResponseOwnedTherapy();

    $this->actingAs($owner)->withHeaders(therapyResponseInertiaHeaders())->from('/therapies/'.$therapy->id)
        ->delete(route('therapies.delete', ['therapyId' => $therapy->id]))
        ->assertRedirect(route('home'));
});

test('a refused JSON end or delete gets the 422 JSON message', function (string $method, string $routeName) {
    [, $therapy] = therapyResponseOwnedTherapy();

    $this->actingAs(User::factory()->adult()->create())
        ->{$method}(route($routeName, ['therapyId' => $therapy->id]))
        ->assertStatus(422)
        ->assertJsonStructure(['message']);

    expect(Therapy::find($therapy->id))->not->toBeNull();
})->with([
    'end' => ['postJson', 'api.therapies.end'],
    'delete' => ['deleteJson', 'api.therapies.delete'],
]);

test('the strict payment gate answers JSON callers with JSON and Inertia with the redirect', function () {
    $client = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id,
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'strictPaymentGate' => false],
    ]);
    $url = route('therapies.strict_payment_gate.update', ['therapyId' => $therapy->id]);

    $this->actingAs($counsellorUser)->patchJson($url, ['strictPaymentGate' => true])
        ->assertOk()->assertJsonPath('status', true);
    $this->actingAs($counsellorUser)->from('/therapies/'.$therapy->id)
        ->withHeaders(therapyResponseInertiaHeaders())->patch($url, ['strictPaymentGate' => false])
        ->assertRedirect('/therapies/'.$therapy->id)->assertSessionHasNoErrors();

    expect($therapy->fresh()->payment_data['strictPaymentGate'])->toBeFalse();
});
