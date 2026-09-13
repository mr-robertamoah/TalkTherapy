<?php

use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\Administrator;
use App\Models\Request;
use App\Models\User;
use App\Services\RequestService;

// TT-4.11d/SCRUM-305: an ageVerification request is ALWAYS null-`to` (admin-only by design, no
// guardian counterpart) -- without this additive branch it would never appear in ANY admin's
// personal requests listing, mirroring dobChange's identical null-`to` admin-visibility branch
// (TT-4.10e/SCRUM-294) exactly.

test('an admin sees a pending ageVerification request in their own personal requests list', function () {
    $admin = User::factory()->has(Administrator::factory())->create();
    $user = User::factory()->create();

    $request = Request::factory()->for($user, 'from')->for($user, 'for')->create([
        'type' => RequestTypeEnum::ageVerification->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => ['attestation' => 'I am 25 years old.', 'attestedDob' => now()->subYears(25)->toDateString()],
    ]);

    $resources = RequestService::new()->getRequests(RequestStatusEnum::pending->value, $admin);

    expect($resources->collection->pluck('id'))->toContain($request->id);
});

test('a non-admin never sees another user\'s ageVerification request', function () {
    $nonAdmin = User::factory()->create();
    $user = User::factory()->create();

    Request::factory()->for($user, 'from')->for($user, 'for')->create([
        'type' => RequestTypeEnum::ageVerification->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => ['attestation' => 'I am 25 years old.', 'attestedDob' => now()->subYears(25)->toDateString()],
    ]);

    $resources = RequestService::new()->getRequests(RequestStatusEnum::pending->value, $nonAdmin);

    expect($resources->collection)->toHaveCount(0);
});

test('an admin sees their own ageVerification request via the admin-visibility branch too', function () {
    $admin = User::factory()->has(Administrator::factory())->create();

    $request = Request::factory()->for($admin, 'from')->for($admin, 'for')->create([
        'type' => RequestTypeEnum::ageVerification->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => ['attestation' => 'I am an adult.', 'attestedDob' => now()->subYears(25)->toDateString()],
    ]);

    $resources = RequestService::new()->getRequests(RequestStatusEnum::pending->value, $admin);

    expect($resources->collection->pluck('id'))->toContain($request->id);
});
