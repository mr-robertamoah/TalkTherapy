<?php

use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Http\Resources\RequestResource;
use App\Models\File;
use App\Models\Request;
use App\Models\User;

// TT-4.11d/SCRUM-305: RequestResource -- used by the personal "my requests" list endpoint
// (RequestService::getRequests()), a DIFFERENT serialization path from
// AgeVerificationRequestResource (used only by the accept/reject endpoint) -- needs its own
// ageVerification handling: `to` is always null (never a deleted-counsellor placeholder), the
// attestation/attestedDob/documentUrl need to be exposed, and `from`/`for` (always the same
// submitting user) must be narrowed the same way dobChange's null-`to` case already is, since
// this is visible to EVERY admin, not just whichever one eventually responds.

function resolveAgeVerificationResource(RequestResource $resource): array
{
    return json_decode(json_encode($resource), true);
}

test('an ageVerification request resolves `to` as null, not a deleted-counsellor placeholder', function () {
    $user = User::factory()->create();

    $requestModel = Request::factory()->for($user, 'from')->for($user, 'for')->create([
        'type' => RequestTypeEnum::ageVerification->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => ['attestation' => 'I confirm my dob is accurate.', 'attestedDob' => now()->subYears(25)->toDateString()],
    ]);

    $array = resolveAgeVerificationResource(new RequestResource($requestModel));

    expect($array['to'])->toBeNull();
});

test('an ageVerification request carries its attestation and attestedDob through the generic list resource', function () {
    $user = User::factory()->create();

    $requestModel = Request::factory()->for($user, 'from')->for($user, 'for')->create([
        'type' => RequestTypeEnum::ageVerification->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => ['attestation' => 'I am 25 years old.', 'attestedDob' => '1999-01-01'],
    ]);

    $array = resolveAgeVerificationResource(new RequestResource($requestModel));

    expect($array['ageVerification']['attestation'])->toBe('I am 25 years old.');
    expect($array['ageVerification']['attestedDob'])->toBe('1999-01-01');
});

test('an ageVerification request with no document has a null documentUrl', function () {
    $user = User::factory()->create();

    $requestModel = Request::factory()->for($user, 'from')->for($user, 'for')->create([
        'type' => RequestTypeEnum::ageVerification->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => ['attestation' => 'I am 25 years old.', 'attestedDob' => '1999-01-01'],
    ]);

    $array = resolveAgeVerificationResource(new RequestResource($requestModel));

    expect($array['ageVerification']['documentUrl'])->toBeNull();
});

test('an ageVerification request with a document exposes an authenticated retrieval route, never a raw file URL', function () {
    $user = User::factory()->create();

    $requestModel = Request::factory()->for($user, 'from')->for($user, 'for')->create([
        'type' => RequestTypeEnum::ageVerification->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => ['attestation' => 'I am 25 years old.', 'attestedDob' => '1999-01-01'],
    ]);
    $file = File::factory()->create(['storage' => 'identity_documents']);
    $requestModel->identityDocument()->sync([$file->id]);

    $array = resolveAgeVerificationResource(new RequestResource($requestModel->fresh()));

    expect($array['ageVerification']['documentUrl'])->toBe(
        route('requests.documents.show', ['request' => $requestModel->id, 'file' => $file->id])
    );
});

test('an ageVerification request narrows `for` to id/fullName/username, not full PII', function () {
    $user = User::factory()->create(['dob' => now()->subYears(25)->toDateString(), 'gender' => 'FEMALE', 'country' => 'Ghana']);

    $requestModel = Request::factory()->for($user, 'from')->for($user, 'for')->create([
        'type' => RequestTypeEnum::ageVerification->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => ['attestation' => 'I am 25 years old.', 'attestedDob' => now()->subYears(25)->toDateString()],
    ]);

    $array = resolveAgeVerificationResource(new RequestResource($requestModel));

    expect($array['for'])->toBe([
        'id' => $user->id,
        'fullName' => $user->name,
        'username' => $user->username,
        'isUser' => true,
    ]);
    expect($array['for'])->not->toHaveKey('gender');
    expect($array['for'])->not->toHaveKey('dob');
});

test('an ageVerification request narrows `from` to id/fullName/username, not full PII', function () {
    $user = User::factory()->create(['dob' => now()->subYears(25)->toDateString(), 'gender' => 'FEMALE', 'country' => 'Ghana']);

    $requestModel = Request::factory()->for($user, 'from')->for($user, 'for')->create([
        'type' => RequestTypeEnum::ageVerification->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => ['attestation' => 'I am 25 years old.', 'attestedDob' => now()->subYears(25)->toDateString()],
    ]);

    $array = resolveAgeVerificationResource(new RequestResource($requestModel));

    expect($array['from'])->toBe([
        'id' => $user->id,
        'fullName' => $user->name,
        'username' => $user->username,
        'isUser' => true,
    ]);
    expect($array['from'])->not->toHaveKey('gender');
    expect($array['from'])->not->toHaveKey('dob');
});

test('a non-ageVerification request never gets an `ageVerification` key at all', function () {
    $guardian = User::factory()->create();
    $ward = User::factory()->create();

    $requestModel = Request::factory()->for($ward, 'from')->for($guardian, 'to')->create([
        'type' => RequestTypeEnum::guardianship->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => [],
    ]);

    $array = resolveAgeVerificationResource(new RequestResource($requestModel));

    expect($array)->not->toHaveKey('ageVerification');
});
