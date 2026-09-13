<?php

use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\Guardianship;
use App\Models\Request;
use App\Models\User;
use App\Services\RequestService;

// TT-4.10f/SCRUM-295 QA finding: EnsureUserCanRespondToRequestAction already authorizes ANY of a
// ward's guardians to respond to a dobChange request, not just the one `to` happens to be fixed
// to at creation time -- but RequestService::getRequests() only ever matched whereTo($user), so a
// ward's OTHER guardian had no way to discover the request existed at all through the personal
// Requests list/modal. This additive branch closes that gap.

test('a ward\'s second guardian (not the one `to` names) sees the dobChange request in their own personal requests list', function () {
    $ward = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    $guardianA = User::factory()->create();
    $guardianB = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardianA->id, 'ward_id' => $ward->id]);
    Guardianship::query()->create(['guardian_id' => $guardianB->id, 'ward_id' => $ward->id]);

    $request = Request::factory()->for($ward, 'from')->for($guardianA, 'to')->for($ward, 'for')->create([
        'type' => RequestTypeEnum::dobChange->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => ['newDob' => now()->subYears(30)->toDateString(), 'priorDob' => now()->subYears(17)->toDateString()],
    ]);

    $resources = RequestService::new()->getRequests(RequestStatusEnum::pending->value, $guardianB);

    expect($resources->collection->pluck('id'))->toContain($request->id);
});

test('a guardian of an unrelated ward never sees someone else\'s ward\'s dobChange request', function () {
    $ward = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    $guardianA = User::factory()->create();
    $unrelatedWard = User::factory()->create();
    $unrelatedGuardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardianA->id, 'ward_id' => $ward->id]);
    Guardianship::query()->create(['guardian_id' => $unrelatedGuardian->id, 'ward_id' => $unrelatedWard->id]);

    Request::factory()->for($ward, 'from')->for($guardianA, 'to')->for($ward, 'for')->create([
        'type' => RequestTypeEnum::dobChange->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => ['newDob' => now()->subYears(30)->toDateString(), 'priorDob' => now()->subYears(17)->toDateString()],
    ]);

    $resources = RequestService::new()->getRequests(RequestStatusEnum::pending->value, $unrelatedGuardian);

    expect($resources->collection)->toHaveCount(0);
});
