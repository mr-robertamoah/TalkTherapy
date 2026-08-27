<?php

use App\Enums\SessionTypeEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;

// Regression tests for SCRUM-116: Illuminate\Http\Request::__get() prefers a same-named
// parsed-body/query key over the route parameter, so SessionController/TherapyController/
// GroupTherapyController resolving their target via the magic ->sessionId/->therapyId/
// ->groupTherapyId properties let a client override which record a request actually acts on,
// just by adding an extra body field of the same name -- the URL became purely decorative.
// Same class of bug SCRUM-110 already fixed in TransactionController::getFor().
//
// The therapy/group-therapy update requests below deliberately include public/allowInPerson/
// anonymous(/allowAnyone/shareEqually) explicitly -- omitting them crashes for an unrelated,
// separately-filed reason (SCRUM-127: CreateTherapyDTO/GroupTherapyDTO type these non-nullable,
// so a request that omits them throws). Not this ticket's bug; worked around here so this test
// verifies only what it's meant to.

test('updating a session applies to the URL\'s session, not a spoofed sessionId in the body', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapyOwner = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $therapyOwner->id,
        'counsellor_id' => $counsellor->id,
    ]);
    $ownedSession = Session::factory()->create([
        'addedby_type' => Counsellor::class,
        'addedby_id' => $counsellor->id,
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'type' => SessionTypeEnum::online->value,
        'start_time' => now()->addHours(2),
        'end_time' => now()->addHours(3),
    ]);

    // An unrelated session, added by a different counsellor -- if the magic-property bug were
    // still present, this is the one that would actually get updated instead.
    //
    // start_time/end_time are pinned safely in the past: EnsureSessionDataIsValidAction and
    // Timeable::isNotUpdateable() both run conflict checks against ALL sessions in the DB (not
    // scoped to this therapy), so leaving these on the factory's default `$this->faker->timezone()`
    // (not a real date) makes whether they land near "now" -- and so whether they spuriously
    // trip those global checks against $ownedSession's update -- essentially random.
    $unrelatedSession = Session::factory()->create([
        'addedby_type' => Counsellor::class,
        'addedby_id' => Counsellor::factory()->create(['user_id' => User::factory()])->id,
        'for_type' => Therapy::class,
        'for_id' => Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()])->id,
        'name' => 'Untouched',
        'start_time' => now()->subDays(3),
        'end_time' => now()->subDays(3)->addHour(),
    ]);

    $this->actingAs($counsellorUser);

    $response = $this->patchJson("/therapies/{$therapy->id}/sessions/{$ownedSession->id}", [
        'sessionId' => $unrelatedSession->id,
        'name' => 'Renamed via owned session',
        'startTime' => now()->addHours(4),
        'endTime' => now()->addHours(5),
    ]);

    $response->assertOk();
    expect($ownedSession->refresh()->name)->toBe('Renamed via owned session');
    expect($unrelatedSession->refresh()->name)->toBe('Untouched');
});

test('updating a therapy applies to the URL\'s therapy, not a spoofed therapyId in the body', function () {
    $owner = User::factory()->create();
    $ownedTherapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $owner->id,
        'name' => 'Original',
    ]);

    $unrelatedTherapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'name' => 'Untouched',
    ]);

    $this->actingAs($owner);

    $response = $this->patch("/therapies/{$ownedTherapy->id}", [
        'therapyId' => $unrelatedTherapy->id,
        'name' => 'Renamed via owned therapy',
        'public' => true,
        'allowInPerson' => true,
        'anonymous' => false,
    ]);

    $response->assertSessionHasNoErrors();
    expect($ownedTherapy->refresh()->name)->toBe('Renamed via owned therapy');
    expect($unrelatedTherapy->refresh()->name)->toBe('Untouched');
});

test('updating a group therapy applies to the URL\'s group therapy, not a spoofed groupTherapyId in the body', function () {
    $owner = User::factory()->create();
    $ownedGroupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $owner->id,
        'name' => 'Original',
    ]);

    $unrelatedGroupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'name' => 'Untouched',
    ]);

    $this->actingAs($owner);

    $response = $this->patch("/group-therapies/{$ownedGroupTherapy->id}", [
        'groupTherapyId' => $unrelatedGroupTherapy->id,
        'name' => 'Renamed via owned group therapy',
        'public' => true,
        'allowInPerson' => true,
        'anonymous' => false,
        'allowAnyone' => true,
        'shareEqually' => true,
        'counsellorIds' => [],
    ]);

    $response->assertSessionHasNoErrors();
    expect($ownedGroupTherapy->refresh()->name)->toBe('Renamed via owned group therapy');
    expect($unrelatedGroupTherapy->refresh()->name)->toBe('Untouched');
});
