<?php

use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\Counsellor;
use App\Models\Request;
use App\Models\Therapy;
use App\Models\User;

function aCounsellor()
{
    $user = User::factory()->create();

    return Counsellor::factory()->create(['user_id' => $user->id]);
}

function aTherapy(User $addedby)
{
    return Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $addedby->id,
    ]);
}

test('a counsellor with a pending request for the given model has a pending request for it', function () {
    $counsellor = aCounsellor();
    $therapy = aTherapy(User::factory()->create());

    Request::query()->forceCreate([
        'data' => [],
        'type' => RequestTypeEnum::therapy->value,
        'status' => RequestStatusEnum::pending->value,
        'from_type' => Counsellor::class,
        'from_id' => $counsellor->id,
        'to_type' => User::class,
        'to_id' => $therapy->addedby_id,
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
    ]);

    expect($counsellor->hasPendingRequestFor($therapy))->toBeTrue();
    expect($counsellor->doesNotHavePendingRequestFor($therapy))->toBeFalse();
});

test('a counsellor with an unrelated, already-accepted request for a different therapy does not appear to have a pending request for a new one', function () {
    $counsellor = aCounsellor();
    $unrelatedTherapy = aTherapy(User::factory()->create());
    $newTherapy = aTherapy(User::factory()->create());

    // An old, already-accepted request for a completely different therapy -- this must not
    // leak into the pending check for $newTherapy. Before the fix, an ungrouped
    // ->whereTo($this)->orWhereFrom($this) made orWhereFrom() a top-level OR unscoped from
    // wherePending()/whereFor($model), so this row alone made hasPendingRequestFor() return
    // true for ANY therapy, regardless of status or target.
    Request::query()->forceCreate([
        'data' => [],
        'type' => RequestTypeEnum::therapy->value,
        'status' => RequestStatusEnum::accepted->value,
        'from_type' => Counsellor::class,
        'from_id' => $counsellor->id,
        'to_type' => User::class,
        'to_id' => $unrelatedTherapy->addedby_id,
        'for_type' => Therapy::class,
        'for_id' => $unrelatedTherapy->id,
    ]);

    expect($counsellor->hasPendingRequestFor($newTherapy))->toBeFalse();
    expect($counsellor->doesNotHavePendingRequestFor($newTherapy))->toBeTrue();
});

test('a counsellor with no request at all does not have a pending request for a therapy', function () {
    $counsellor = aCounsellor();
    $therapy = aTherapy(User::factory()->create());

    expect($counsellor->hasPendingRequestFor($therapy))->toBeFalse();
});
