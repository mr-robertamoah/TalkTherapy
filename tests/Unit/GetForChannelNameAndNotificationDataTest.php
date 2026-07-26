<?php

use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;

test('Session::getForChannelName uses camelCase groupTherapies, matching the registered presence channel', function () {
    // Regression guard for SCRUM-58: routes/channels.php registers "groupTherapies.{id}"
    // (camelCase) and the frontend joins the same, but this used to return the all-lowercase
    // "grouptherapies.{id}" -- channel names are case-sensitive, so broadcasts silently never
    // reached the channel the frontend actually joined.
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
    ]);
    $session = Session::factory()->create([
        'for_id' => $groupTherapy->id,
        'for_type' => GroupTherapy::class,
    ]);

    expect($session->getForChannelName())->toBe("groupTherapies.{$groupTherapy->id}");
});

test('Session::getForChannelName is unaffected for an individual therapy', function () {
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
    ]);
    $session = Session::factory()->create([
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
    ]);

    expect($session->getForChannelName())->toBe("therapies.{$therapy->id}");
});

test('Discussion::getForChannelName uses camelCase groupTherapies, matching the registered presence channel', function () {
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
    ]);
    $discussion = Discussion::factory()->create([
        'for_id' => $groupTherapy->id,
        'for_type' => GroupTherapy::class,
    ]);

    expect($discussion->getForChannelName())->toBe("groupTherapies.{$groupTherapy->id}");
});

test('Discussion::getForChannelName is unaffected for an individual therapy', function () {
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
    ]);
    $discussion = Discussion::factory()->create([
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
    ]);

    expect($discussion->getForChannelName())->toBe("therapies.{$therapy->id}");
});

test('Session::getNotificationActionData links to the real hyphenated group-therapies route', function () {
    // Regression guard for SCRUM-58 (found during reviewer's follow-up pass): this used
    // url("group_therapies/{id}") (underscore), but the actual registered route is
    // "/group-therapies/{groupTherapyId}" (hyphenated) -- consumed by 14 notification classes,
    // every one of their "Visit Group Therapy Page" email links 404'd.
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
    ]);
    $session = Session::factory()->create([
        'for_id' => $groupTherapy->id,
        'for_type' => GroupTherapy::class,
    ]);

    [$type, $url] = $session->getNotificationActionData();

    expect($type)->toBe('Group Therapy');
    expect($url)->toBe(url("group-therapies/{$groupTherapy->id}"));
});

test('Session::getNotificationActionData is unaffected for an individual therapy', function () {
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
    ]);
    $session = Session::factory()->create([
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
    ]);

    [$type, $url] = $session->getNotificationActionData();

    expect($type)->toBe('Therapy');
    expect($url)->toBe(url("therapies/{$therapy->id}"));
});

test('Discussion::getNotificationActionData links to the real hyphenated group-therapies route', function () {
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
    ]);
    $discussion = Discussion::factory()->create([
        'for_id' => $groupTherapy->id,
        'for_type' => GroupTherapy::class,
    ]);

    [$type, $url] = $discussion->getNotificationActionData();

    expect($type)->toBe('Group Therapy');
    expect($url)->toBe(url("group-therapies/{$groupTherapy->id}"));
});

test('Discussion::getNotificationActionData is unaffected for an individual therapy', function () {
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
    ]);
    $discussion = Discussion::factory()->create([
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
    ]);

    [$type, $url] = $discussion->getNotificationActionData();

    expect($type)->toBe('Therapy');
    expect($url)->toBe(url("therapies/{$therapy->id}"));
});
