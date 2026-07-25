<?php

use App\Actions\Request\GetRequestResourceAction;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Http\Resources\AdminCounsellorVerificationRequestResource;
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
