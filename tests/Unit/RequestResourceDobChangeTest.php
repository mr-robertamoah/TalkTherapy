<?php

use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Http\Resources\RequestResource;
use App\Models\Request;
use App\Models\User;

// `when()`'s false branches are only actually stripped out during real JSON serialization, not
// when merely inspecting a resource's raw ->toArray() return in PHP -- resolving through
// json_encode/json_decode mirrors what an actual HTTP response looks like.
function resolveRequestResource(RequestResource $resource): array
{
    return json_decode(json_encode($resource), true);
}

// TT-4.10e/SCRUM-294 (found during manual QA, no prior automated coverage): RequestResource --
// used by the personal "my requests" list endpoint (RequestService::getRequests()), a DIFFERENT
// serialization path from DobChangeRequestResource (used only by the accept/reject endpoint) --
// previously had no idea about dobChange at all: `to` fell through to
// CounsellorMiniResource(null), misrepresenting a genuinely-unassigned request as "the guardian's
// account was deleted," and newDob/priorDob were never included at all (rendering as a blank/
// invalid value in the request-list UI).

test('a dobChange request addressed to a specific guardian resolves `to` as that guardian', function () {
    $target = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    $guardian = User::factory()->create();

    $requestModel = Request::factory()->for($target, 'from')->for($guardian, 'to')->for($target, 'for')->create([
        'type' => RequestTypeEnum::dobChange->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => ['newDob' => now()->subYears(30)->toDateString(), 'priorDob' => now()->subYears(17)->toDateString()],
    ]);

    $array = resolveRequestResource(new RequestResource($requestModel));

    expect($array['to']['id'])->toBe($guardian->id);
    expect($array['to'])->not->toHaveKey('deleted');
});

test('a dobChange request with a null `to` (no guardian) resolves `to` as null, not a deleted-counsellor placeholder', function () {
    $target = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);

    $requestModel = Request::factory()->for($target, 'from')->for($target, 'for')->create([
        'type' => RequestTypeEnum::dobChange->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => ['newDob' => now()->subYears(30)->toDateString(), 'priorDob' => now()->subYears(17)->toDateString()],
    ]);

    $array = resolveRequestResource(new RequestResource($requestModel));

    expect($array['to'])->toBeNull();
});

test('a dobChange request carries its proposed newDob/priorDob through the generic list resource', function () {
    $target = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    $guardian = User::factory()->create();

    $requestModel = Request::factory()->for($target, 'from')->for($guardian, 'to')->for($target, 'for')->create([
        'type' => RequestTypeEnum::dobChange->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => ['newDob' => '1996-01-01', 'priorDob' => '2011-09-12'],
    ]);

    $array = resolveRequestResource(new RequestResource($requestModel));

    expect($array['dobChange']['newDob'])->toBe('1996-01-01');
    expect($array['dobChange']['priorDob'])->toBe('2011-09-12');
});

test('a null-`to` dobChange request narrows `for` to id/fullName/username, not full PII', function () {
    $target = User::factory()->create([
        'dob' => now()->subYears(17)->toDateString(),
        'gender' => 'FEMALE',
        'country' => 'Ghana',
    ]);

    $requestModel = Request::factory()->for($target, 'from')->for($target, 'for')->create([
        'type' => RequestTypeEnum::dobChange->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => ['newDob' => now()->subYears(30)->toDateString(), 'priorDob' => now()->subYears(17)->toDateString()],
    ]);

    $array = resolveRequestResource(new RequestResource($requestModel));

    expect($array['for'])->toBe([
        'id' => $target->id,
        'fullName' => $target->name,
        'username' => $target->username,
        'isUser' => true,
    ]);
    expect($array['for'])->not->toHaveKey('gender');
    expect($array['for'])->not->toHaveKey('country');
    expect($array['for'])->not->toHaveKey('dob');
});

test('a null-`to` dobChange request also narrows `from` to id/fullName/username, not full PII', function () {
    // Security review finding: a self-service dob edit sets `from` = `for` = the same ward
    // (ProfileController::update() -> EnsureDobChangeIsAllowedAction with actor == user), so
    // narrowing `for` alone still re-leaked the identical PII to every admin via `from`.
    $target = User::factory()->create([
        'dob' => now()->subYears(17)->toDateString(),
        'gender' => 'FEMALE',
        'country' => 'Ghana',
    ]);

    $requestModel = Request::factory()->for($target, 'from')->for($target, 'for')->create([
        'type' => RequestTypeEnum::dobChange->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => ['newDob' => now()->subYears(30)->toDateString(), 'priorDob' => now()->subYears(17)->toDateString()],
    ]);

    $array = resolveRequestResource(new RequestResource($requestModel));

    expect($array['from'])->toBe([
        'id' => $target->id,
        'fullName' => $target->name,
        'username' => $target->username,
        'isUser' => true,
    ]);
    expect($array['from'])->not->toHaveKey('gender');
    expect($array['from'])->not->toHaveKey('country');
    expect($array['from'])->not->toHaveKey('dob');
});

test('a dobChange request addressed to a specific guardian does not narrow `from`', function () {
    $target = User::factory()->create(['dob' => now()->subYears(17)->toDateString(), 'gender' => 'FEMALE']);
    $guardian = User::factory()->create();

    $requestModel = Request::factory()->for($target, 'from')->for($guardian, 'to')->for($target, 'for')->create([
        'type' => RequestTypeEnum::dobChange->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => ['newDob' => now()->subYears(30)->toDateString(), 'priorDob' => now()->subYears(17)->toDateString()],
    ]);

    $array = resolveRequestResource(new RequestResource($requestModel));

    expect($array['from'])->toHaveKey('gender');
});

test('a dobChange request addressed to a specific guardian does not narrow `for`', function () {
    $target = User::factory()->create(['dob' => now()->subYears(17)->toDateString(), 'gender' => 'FEMALE']);
    $guardian = User::factory()->create();

    $requestModel = Request::factory()->for($target, 'from')->for($guardian, 'to')->for($target, 'for')->create([
        'type' => RequestTypeEnum::dobChange->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => ['newDob' => now()->subYears(30)->toDateString(), 'priorDob' => now()->subYears(17)->toDateString()],
    ]);

    $array = resolveRequestResource(new RequestResource($requestModel));

    expect($array['for'])->toHaveKey('gender');
});

test('a non-dobChange request never gets a `dobChange` key at all', function () {
    $guardian = User::factory()->create();
    $ward = User::factory()->create();

    $requestModel = Request::factory()->for($ward, 'from')->for($guardian, 'to')->create([
        'type' => RequestTypeEnum::guardianship->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => [],
    ]);

    $array = resolveRequestResource(new RequestResource($requestModel));

    expect($array)->not->toHaveKey('dobChange');
});
