<?php

use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\Request;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// TT-4.11b/SCRUM-303: the real HTTP submission endpoint.

test('an authenticated user can submit a self-attestation with no document', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('api.users.age-verification'), [
        'attestation' => 'I am 25 years old, born in 2001.',
    ]);

    $response->assertSuccessful();
    $request = Request::query()->whereType(RequestTypeEnum::ageVerification->value)->whereFrom($user)->first();
    expect($request)->not->toBeNull();
    expect($request->status)->toBe(RequestStatusEnum::pending->value);
});

test('an authenticated user can submit a self-attestation with an optional document', function () {
    Storage::fake('identity_documents');
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('api.users.age-verification'), [
        'attestation' => 'I am 25 years old, born in 2001.',
        'document' => UploadedFile::fake()->image('id.jpg'),
    ]);

    $response->assertSuccessful();
    $request = Request::query()->whereType(RequestTypeEnum::ageVerification->value)->whereFrom($user)->first();
    expect($request->identityDocument()->count())->toBe(1);
});

test('an attestation is required', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('api.users.age-verification'), []);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('attestation');
});

test('an attestation shorter than the minimum length is rejected', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('api.users.age-verification'), [
        'attestation' => 'too short',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('attestation');
});

test('a guest cannot submit an age-verification request', function () {
    $response = $this->postJson(route('api.users.age-verification'), [
        'attestation' => 'I am 25 years old, born in 2001.',
    ]);

    $response->assertStatus(401);
});
