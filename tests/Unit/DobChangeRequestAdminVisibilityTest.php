<?php

use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\Administrator;
use App\Models\Guardianship;
use App\Models\Request;
use App\Models\User;
use App\Services\RequestService;

// TT-4.10e/SCRUM-294: refund's own null-`to` case ("any admin may respond") is surfaced via a
// completely separate admin-only page, never the generic personal Requests list -- but this
// ticket's own scope explicitly asks to reuse that generic list/modal UI for dobChange instead of
// building a new page, so RequestService::getRequests() needs an additive branch making a
// null-`to` dobChange request visible to any admin there.

test('an admin sees a null-`to` dobChange request (no guardian exists) in their own personal requests list', function () {
    $admin = User::factory()->has(Administrator::factory())->create();
    $target = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);

    $request = Request::factory()->for($target, 'from')->for($target, 'for')->create([
        'type' => RequestTypeEnum::dobChange->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => ['newDob' => now()->subYears(30)->toDateString(), 'priorDob' => now()->subYears(17)->toDateString()],
    ]);

    $resources = RequestService::new()->getRequests(RequestStatusEnum::pending->value, $admin);

    expect($resources->collection->pluck('id'))->toContain($request->id);
});

test('a non-admin never sees a null-`to` dobChange request that isn\'t theirs', function () {
    $nonAdmin = User::factory()->create();
    $target = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);

    Request::factory()->for($target, 'from')->for($target, 'for')->create([
        'type' => RequestTypeEnum::dobChange->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => ['newDob' => now()->subYears(30)->toDateString(), 'priorDob' => now()->subYears(17)->toDateString()],
    ]);

    $resources = RequestService::new()->getRequests(RequestStatusEnum::pending->value, $nonAdmin);

    expect($resources->collection)->toHaveCount(0);
});

test('an admin still sees a dobChange request explicitly addressed to a specific guardian only via the normal `to` match, not the admin-visibility branch', function () {
    $admin = User::factory()->has(Administrator::factory())->create();
    $guardian = User::factory()->create();
    $target = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $target->id]);

    Request::factory()->for($target, 'from')->for($guardian, 'to')->for($target, 'for')->create([
        'type' => RequestTypeEnum::dobChange->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => ['newDob' => now()->subYears(30)->toDateString(), 'priorDob' => now()->subYears(17)->toDateString()],
    ]);

    // The admin-visibility branch only matches a genuinely null `to` -- an admin who is not the
    // addressed guardian must not see this one, since a real guardian exists and should not be
    // bypassed by an always-available admin route.
    $resources = RequestService::new()->getRequests(RequestStatusEnum::pending->value, $admin);

    expect($resources->collection)->toHaveCount(0);
});
