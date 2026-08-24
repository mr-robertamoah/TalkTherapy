<?php

use App\Http\Resources\GroupTherapyMiniResource;
use App\Http\Resources\GroupTherapyResource;
use App\Http\Resources\TherapyResource;
use App\Models\GroupTherapy;
use App\Models\Therapy;
use App\Models\User;

// SCRUM-75: TherapyResource/GroupTherapyResource/GroupTherapyMiniResource used to mask an
// anonymous addedby's identity as the bare, unstyled string 'anonymous', while
// routes/channels.php's presence-channel masking (the newer, deliberate convention) used
// 'Client (Anonymous User)' -- a visible copy inconsistency on the same page for the same
// masked person. All resource-level masking must use the channels.php string.

function anAnonymousTherapyViewedByAStranger(): array
{
    $creator = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
        'anonymous' => true,
    ]);
    $stranger = User::factory()->create();

    $fakeRequest = request();
    $fakeRequest->setUserResolver(fn () => $stranger);

    return [$therapy, $fakeRequest];
}

test('TherapyResource masks an anonymous addedby with the channels.php convention', function () {
    [$therapy, $fakeRequest] = anAnonymousTherapyViewedByAStranger();

    $array = (new TherapyResource($therapy))->toArray($fakeRequest);

    expect($array['user']['fullName'])->toBe('Client (Anonymous User)');
});

test('GroupTherapyResource masks an anonymous addedby with the channels.php convention', function () {
    $creator = User::factory()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
        'anonymous' => true,
    ]);
    $stranger = User::factory()->create();

    $fakeRequest = request();
    $fakeRequest->setUserResolver(fn () => $stranger);

    $array = (new GroupTherapyResource($groupTherapy))->toArray($fakeRequest);

    expect($array['addedby']['fullName'])->toBe('Client (Anonymous User)');
});

test('GroupTherapyMiniResource masks an anonymous addedby with the channels.php convention', function () {
    $creator = User::factory()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
        'anonymous' => true,
    ]);
    $stranger = User::factory()->create();

    $fakeRequest = request();
    $fakeRequest->setUserResolver(fn () => $stranger);

    $array = (new GroupTherapyMiniResource($groupTherapy))->toArray($fakeRequest);

    expect($array['addedby']['fullName'])->toBe('Client (Anonymous User)');
});
