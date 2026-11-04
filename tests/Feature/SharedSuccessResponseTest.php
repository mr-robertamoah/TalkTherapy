<?php

use App\Enums\ConstantsEnum;
use App\Enums\LicensingAuthorityTypeEnum;
use App\Enums\LicensingTypeEnum;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\LicensingAuthority;
use App\Models\User;
use App\Support\SuccessResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;

// SCRUM-380: one success response (App\Support\SuccessResponse, via the base Controller's
// successResponse()) replaced every controller's private returnSuccess, and GroupTherapyController's
// update/payment-gate/end/delete plus CounsellorController::verifyCounsellor moved onto it and
// returnFailure(). JSON callers get JSON; Inertia visits get the same redirect (and errors.alert) as before.

function ssrInertiaHeaders(): array
{
    return ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html, application/xhtml+xml'];
}

function ssrRequest(array $headers): Request
{
    $request = Request::create('/anything', 'POST', server: collect($headers)
        ->mapWithKeys(fn ($value, $name) => ['HTTP_'.strtoupper(str_replace('-', '_', $name)) => $value])->all());
    $request->setLaravelSession(app('session.store'));

    return $request;
}

function ssrGroupOwnedBy(User $owner, array $attributes = []): GroupTherapy
{
    return GroupTherapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $owner->id, 'name' => 'Original', ...$attributes,
    ]);
}

function ssrPaidGroupWithActiveCounsellor(): array
{
    $client = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $group = ssrGroupOwnedBy($client, [
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 100, 'currency' => 'GHS', 'shareEqually' => true, 'strictPaymentGate' => false, 'allowFreeHistoricalAccess' => true],
    ]);
    $group->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);

    return [$client, $counsellorUser, $group];
}

// --- SuccessResponse itself -------------------------------------------------------------------

test('a JSON caller gets the JSON body and nothing is flashed', function () {
    $response = SuccessResponse::make(ssrRequest(['Accept' => 'application/json']), ['post' => ['id' => 7]]);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['post' => ['id' => 7]])
        ->and(session()->has('post'))->toBeFalse();
});

test('a visit with no redirect goes back with the data flashed', function () {
    $response = SuccessResponse::make(ssrRequest(ssrInertiaHeaders()), ['post' => ['id' => 7]]);

    expect($response->isRedirect())->toBeTrue()
        ->and(session('post'))->toBe(['id' => 7]);
});

test('a visit with a redirect gets exactly that redirect and nothing flashed', function () {
    $response = SuccessResponse::make(ssrRequest(ssrInertiaHeaders()), ['status' => true], Redirect::route('home'));

    expect($response->getTargetUrl())->toBe(route('home'))
        ->and(session()->has('status'))->toBeFalse();
});

// --- A resource controller (the long-standing ['key' => Resource] shape) ----------------------

test('a resource endpoint answers JSON with the resource and a visit by going back with it flashed', function () {
    $user = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $payload = ['likeableType' => 'Counsellor', 'likeableId' => $counsellor->id];

    $this->actingAs($user)->postJson(route('api.likes.create'), $payload)
        ->assertOk()->assertJsonStructure(['like']);

    $this->actingAs($user)->postJson(route('api.likes.delete'), $payload)->assertOk();

    $this->actingAs($user)->withHeaders(ssrInertiaHeaders())->from('/counsellor/'.$counsellor->id)
        ->post(route('api.likes.create'), $payload)
        ->assertRedirect('/counsellor/'.$counsellor->id)
        ->assertSessionHas('like')
        ->assertSessionHasNoErrors();
});

// --- GroupTherapyController -------------------------------------------------------------------

test('a JSON group update gets JSON with the updated group', function (string $routeName) {
    $owner = User::factory()->adult()->create();
    $group = ssrGroupOwnedBy($owner);

    $this->actingAs($owner)->patchJson(route($routeName, ['groupTherapyId' => $group->id]), ['name' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('status', true)
        ->assertJsonPath('groupTherapy.id', $group->id)
        ->assertJsonPath('groupTherapy.name', 'Renamed');

    expect($group->fresh()->name)->toBe('Renamed');
})->with(['api.group.therapies.update', 'group.therapies.update']);

test('an Inertia group update is still redirected back, with no errors', function () {
    $owner = User::factory()->adult()->create();
    $group = ssrGroupOwnedBy($owner);

    $this->actingAs($owner)->withHeaders(ssrInertiaHeaders())->from('/group-therapies/'.$group->id)
        ->patch(route('group.therapies.update', ['groupTherapyId' => $group->id]), ['name' => 'Renamed'])
        ->assertRedirect('/group-therapies/'.$group->id)
        ->assertSessionHasNoErrors();

    expect($group->fresh()->name)->toBe('Renamed');
});

test('a refused group endpoint gives a JSON caller the 422 message and a visit the alert', function (string $method, string $routeName, array $payload) {
    $group = ssrGroupOwnedBy(User::factory()->adult()->create());
    $stranger = User::factory()->adult()->create();
    $url = route($routeName, ['groupTherapyId' => $group->id]);

    $this->actingAs($stranger)->{$method.'Json'}($url, $payload)
        ->assertStatus(422)->assertJsonStructure(['message'])->assertJsonMissingPath('status');

    $this->actingAs($stranger)->withHeaders(ssrInertiaHeaders())->from('/group-therapies/'.$group->id)
        ->{$method}($url, $payload)
        ->assertRedirect('/group-therapies/'.$group->id)
        ->assertSessionHasErrors('alert');

    expect($group->fresh())->not->toBeNull()->and($group->fresh()->name)->toBe('Original');
})->with([
    'update' => ['patch', 'api.group.therapies.update', ['name' => 'Renamed']],
    'payment gate' => ['patch', 'group.therapies.payment_gate.update', ['strictPaymentGate' => true]],
    'end' => ['post', 'api.group.therapies.end', []],
    'delete' => ['delete', 'api.group.therapies.delete', []],
]);

test('the group payment gate answers JSON with JSON and a visit with the redirect', function () {
    [, $counsellorUser, $group] = ssrPaidGroupWithActiveCounsellor();
    $url = route('group.therapies.payment_gate.update', ['groupTherapyId' => $group->id]);

    $this->actingAs($counsellorUser)->patchJson($url, ['strictPaymentGate' => true])
        ->assertOk()->assertExactJson(['status' => true]);
    expect($group->fresh()->payment_data['strictPaymentGate'])->toBeTrue();

    $this->actingAs($counsellorUser)->withHeaders(ssrInertiaHeaders())->from('/group-therapies/'.$group->id)
        ->patch($url, ['strictPaymentGate' => false])
        ->assertRedirect('/group-therapies/'.$group->id)->assertSessionHasNoErrors();
    expect($group->fresh()->payment_data['strictPaymentGate'])->toBeFalse();
});

test('a group payment gate change with no setting is refused in both modes', function () {
    [, $counsellorUser, $group] = ssrPaidGroupWithActiveCounsellor();
    $url = route('group.therapies.payment_gate.update', ['groupTherapyId' => $group->id]);
    $message = 'At least one payment gate setting must be provided.';

    $this->actingAs($counsellorUser)->patchJson($url, [])
        ->assertStatus(422)->assertExactJson(['message' => $message]);

    $this->actingAs($counsellorUser)->withHeaders(ssrInertiaHeaders())->from('/group-therapies/'.$group->id)
        ->patch($url, [])
        ->assertRedirect('/group-therapies/'.$group->id)
        ->assertSessionHasErrors(['alert' => $message]);
});

// Ending needs held periodic sessions -- or an admin, which keeps these about the response.
test('a JSON group end gets JSON and a visit is redirected back', function () {
    $group = ssrGroupOwnedBy(User::factory()->adult()->create());
    $admin = User::factory()->has(Administrator::factory())->create();

    $this->actingAs($admin)->postJson(route('api.group.therapies.end', ['groupTherapyId' => $group->id]))
        ->assertOk()->assertExactJson(['status' => true]);

    $other = ssrGroupOwnedBy(User::factory()->adult()->create());
    $this->actingAs($admin)->withHeaders(ssrInertiaHeaders())->from('/group-therapies/'.$other->id)
        ->post(route('group.therapies.end', ['groupTherapyId' => $other->id]))
        ->assertRedirect('/group-therapies/'.$other->id)->assertSessionHasNoErrors();
});

test('a JSON group delete gets JSON and a visit is redirected home', function () {
    $owner = User::factory()->adult()->create();
    $group = ssrGroupOwnedBy($owner);
    $other = ssrGroupOwnedBy($owner);

    $this->actingAs($owner)->deleteJson(route('api.group.therapies.delete', ['groupTherapyId' => $group->id]))
        ->assertOk()->assertExactJson(['status' => true]);
    expect(GroupTherapy::find($group->id))->toBeNull();

    $this->actingAs($owner)->withHeaders(ssrInertiaHeaders())->from('/group-therapies/'.$other->id)
        ->delete(route('group.therapies.delete', ['groupTherapyId' => $other->id]))
        ->assertRedirect(route('home'))->assertSessionHasNoErrors();
    expect(GroupTherapy::find($other->id))->toBeNull();
});

// --- CounsellorController::verifyCounsellor ---------------------------------------------------

function ssrVerificationPayload(): array
{
    return [
        'licenseFile' => UploadedFile::fake()->create('licence.pdf', 10, 'application/pdf'),
        'licenseNumber' => 'X1',
        'licensingAuthorityId' => LicensingAuthority::factory()->create([
            'name' => 'Board '.uniqid(),
            'type' => LicensingAuthorityTypeEnum::values()[0],
            'license_type' => LicensingTypeEnum::both->value,
        ])->id,
        'nationalIdNumber' => 'GHA-123',
    ];
}

test('a verification request answers JSON with JSON and a visit with the redirect back', function (bool $json) {
    Storage::fake('license_documents');
    Storage::fake('identity_documents');
    Notification::fake();
    LicensingAuthority::factory()->create([
        'name' => ConstantsEnum::nationalId->value,
        'type' => LicensingAuthorityTypeEnum::values()[0],
        'license_type' => LicensingTypeEnum::number->value,
    ]);
    $counsellor = Counsellor::factory()->create([
        'user_id' => User::factory()->adult()->create(['gender' => 'MALE'])->id,
        'email' => 'ssr-'.uniqid().'@example.com', 'email_verified_at' => now(), 'phone' => '0240000000',
    ]);
    $url = route('counsellor.verify', ['counsellorId' => $counsellor->id]);

    if ($json) {
        $this->actingAs($counsellor->user)->postJson($url, ssrVerificationPayload())
            ->assertOk()->assertExactJson(['status' => true]);
    } else {
        $this->actingAs($counsellor->user)->withHeaders(ssrInertiaHeaders())->from('/profile')
            ->post($url, ssrVerificationPayload())
            ->assertRedirect('/profile')->assertSessionHasNoErrors();
    }

    expect($counsellor->fresh()->licensesFor()->count())->toBeGreaterThan(0);
})->with(['JSON' => true, 'Inertia' => false]);

test('a refused verification request gives a JSON caller the 422 message and a visit the alert', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $stranger = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id])->user;
    $url = route('counsellor.verify', ['counsellorId' => $counsellor->id]);
    $message = 'You are not authorized to update this counsellor.';

    $this->actingAs($stranger)->postJson($url, ssrVerificationPayload())
        ->assertStatus(422)->assertExactJson(['message' => $message]);

    $this->actingAs($stranger)->withHeaders(ssrInertiaHeaders())->from('/profile')
        ->post($url, ssrVerificationPayload())
        ->assertRedirect('/profile')
        ->assertSessionHasErrors(['alert' => $message]);
});
