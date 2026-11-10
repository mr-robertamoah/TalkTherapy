<?php

use App\Actions\Request\GetRequestResourceAction;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Http\Resources\AdminCounsellorVerificationRequestResource;
use App\Http\Resources\DobChangeRequestResource;
use App\Http\Resources\GroupTherapyMiniResource;
use App\Http\Resources\RequestResource;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Request as RequestModel;
use App\Models\User;

test('a groupTherapy-type request is routed to RequestResource, not the counsellor-verification resource', function () {
    $fromUser = User::factory()->create();
    $toCounsellorUser = User::factory()->create();
    $toCounsellor = Counsellor::factory()->create(['user_id' => $toCounsellorUser->id]);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $fromUser->id,
    ]);

    $requestModel = RequestModel::query()->forceCreate([
        'data' => [],
        'type' => RequestTypeEnum::groupTherapy->value,
        'status' => RequestStatusEnum::pending->value,
        'from_id' => $fromUser->id,
        'from_type' => User::class,
        'to_id' => $toCounsellor->id,
        'to_type' => Counsellor::class,
        'for_id' => $groupTherapy->id,
        'for_type' => GroupTherapy::class,
    ]);

    $resource = GetRequestResourceAction::new()->execute($requestModel->fresh());

    expect($resource)->toBeInstanceOf(RequestResource::class);
    expect($resource)->not->toBeInstanceOf(AdminCounsellorVerificationRequestResource::class);

    $array = $resource->toArray(request());
    expect($array['for'])->toBeInstanceOf(GroupTherapyMiniResource::class);
});

test('a dobChange-type request is routed to DobChangeRequestResource, not the counsellor-verification resource', function () {
    $target = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    $guardian = User::factory()->create();

    $requestModel = RequestModel::query()->forceCreate([
        'data' => ['newDob' => now()->subYears(30)->toDateString(), 'priorDob' => now()->subYears(17)->toDateString()],
        'type' => RequestTypeEnum::dobChange->value,
        'status' => RequestStatusEnum::pending->value,
        'from_id' => $target->id,
        'from_type' => User::class,
        'to_id' => $guardian->id,
        'to_type' => User::class,
        'for_id' => $target->id,
        'for_type' => User::class,
    ]);

    $resource = GetRequestResourceAction::new()->execute($requestModel->fresh());

    expect($resource)->toBeInstanceOf(DobChangeRequestResource::class);
    expect($resource)->not->toBeInstanceOf(AdminCounsellorVerificationRequestResource::class);

    $array = $resource->toArray(request());
    expect($array['to'])->not->toBeNull();
    expect($array['newDob'])->toBe(now()->subYears(30)->toDateString());
    expect($array['priorDob'])->toBe(now()->subYears(17)->toDateString());
});

test('a dobChange-type request with a null `to` (no guardian) resolves `to` as null, not a deleted-user placeholder', function () {
    $target = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);

    $requestModel = RequestModel::query()->forceCreate([
        'data' => ['newDob' => now()->subYears(30)->toDateString(), 'priorDob' => now()->subYears(17)->toDateString()],
        'type' => RequestTypeEnum::dobChange->value,
        'status' => RequestStatusEnum::pending->value,
        'from_id' => $target->id,
        'from_type' => User::class,
        'to_id' => null,
        'to_type' => null,
        'for_id' => $target->id,
        'for_type' => User::class,
    ]);

    $resource = GetRequestResourceAction::new()->execute($requestModel->fresh());

    $array = $resource->toArray(request());
    expect($array['to'])->toBeNull();
});

test('a counsellor-verification-type request is still routed to AdminCounsellorVerificationRequestResource', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);

    $requestModel = RequestModel::query()->forceCreate([
        'data' => [],
        'type' => RequestTypeEnum::counsellor->value,
        'status' => RequestStatusEnum::pending->value,
        'from_id' => $counsellor->id,
        'from_type' => Counsellor::class,
    ]);

    $resource = GetRequestResourceAction::new()->execute($requestModel->fresh());

    expect($resource)->toBeInstanceOf(AdminCounsellorVerificationRequestResource::class);
});
