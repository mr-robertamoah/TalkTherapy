<?php

use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Request as RequestModel;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\GroupTherapyAssistanceRequestSentNotification;
use App\Notifications\TherapyAssistanceRequestSentNotification;

test('GroupTherapyAssistanceRequestSentNotification links to the real group-therapies route', function () {
    // Regression guard for SCRUM-58 (found while investigating the channel-casing bug): the
    // email action URL used url("grouptherapies/{id}"), but the actual registered route is
    // "/group-therapies/{groupTherapyId}" (hyphenated) -- the link 404'd.
    $fromUser = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $fromUser->id,
    ]);
    $request = RequestModel::query()->forceCreate([
        'data' => [],
        'type' => RequestTypeEnum::groupTherapy->value,
        'status' => RequestStatusEnum::pending->value,
        'from_id' => $fromUser->id,
        'from_type' => User::class,
        'to_id' => $counsellor->id,
        'to_type' => Counsellor::class,
        'for_id' => $groupTherapy->id,
        'for_type' => GroupTherapy::class,
    ]);

    $mail = (new GroupTherapyAssistanceRequestSentNotification($request->fresh()))->toMail($counsellor);

    expect($mail->actionUrl)->toBe(url("group-therapies/{$groupTherapy->id}"));
});

test('TherapyAssistanceRequestSentNotification links to the real group-therapies route for a group therapy request', function () {
    $fromUser = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $fromUser->id,
    ]);
    $request = RequestModel::query()->forceCreate([
        'data' => [],
        'type' => RequestTypeEnum::groupTherapy->value,
        'status' => RequestStatusEnum::pending->value,
        'from_id' => $fromUser->id,
        'from_type' => User::class,
        'to_id' => $counsellor->id,
        'to_type' => Counsellor::class,
        'for_id' => $groupTherapy->id,
        'for_type' => GroupTherapy::class,
    ]);

    $mail = (new TherapyAssistanceRequestSentNotification($request->fresh()))->toMail($counsellor);

    expect($mail->actionUrl)->toBe(url("group-therapies/{$groupTherapy->id}"));
});

test('TherapyAssistanceRequestSentNotification still links to the therapies route for an individual therapy request', function () {
    $fromUser = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $fromUser->id,
    ]);
    $request = RequestModel::query()->forceCreate([
        'data' => [],
        'type' => RequestTypeEnum::therapy->value,
        'status' => RequestStatusEnum::pending->value,
        'from_id' => $fromUser->id,
        'from_type' => User::class,
        'to_id' => $counsellor->id,
        'to_type' => Counsellor::class,
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
    ]);

    $mail = (new TherapyAssistanceRequestSentNotification($request->fresh()))->toMail($counsellor);

    expect($mail->actionUrl)->toBe(url("therapies/{$therapy->id}"));
});
