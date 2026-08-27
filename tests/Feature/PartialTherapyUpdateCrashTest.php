<?php

use App\DTOs\CreateTherapyDTO;
use App\DTOs\GroupTherapyDTO;
use App\Models\GroupTherapy;
use App\Models\Therapy;
use App\Models\User;

// Regression tests for SCRUM-116: CreateTherapyDTO/GroupTherapyDTO declared public/allowInPerson/
// anonymous/allowAnyone/shareEqually as non-nullable bool (and counsellorIds as a non-nullable
// array), even though UpdateTherapyRequest/UpdateGroupTherapyRequest mark all of these 'nullable'
// -- a partial update omitting any of them threw a TypeError at DTO-assignment time, caught by
// the controller's generic catch and surfaced as an unhelpful 500. UpdateTherapyAction/
// UpdateGroupTherapyAction already skip null values via setValueOnData() -- the fix is purely
// widening the DTO property types, not new skip logic.

test('a minimal partial therapy update (just name) does not crash', function () {
    $owner = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $owner->id,
        'name' => 'Original',
    ]);

    $response = $this
        ->actingAs($owner)
        ->patch("/therapies/{$therapy->id}", ['name' => 'Renamed']);

    $response->assertSessionHasNoErrors();
    expect($therapy->refresh()->name)->toBe('Renamed');
});

test('a minimal partial group therapy update (just name) does not crash', function () {
    $owner = User::factory()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $owner->id,
        'name' => 'Original',
    ]);

    $response = $this
        ->actingAs($owner)
        ->patch("/group-therapies/{$groupTherapy->id}", ['name' => 'Renamed']);

    $response->assertSessionHasNoErrors();
    expect($groupTherapy->refresh()->name)->toBe('Renamed');
});

// The same root cause (non-nullable DTO property, nullable-per-validation request field) was
// also reachable on group therapy *creation*: CreateGroupTherapyRequest already allows
// counsellorIds to be omitted, and has no validation rule for shareEqually at all -- both would
// have thrown at fromArray() time before this fix, regardless of the update path above.

test('CreateTherapyDTO::fromArray does not crash when public/allowInPerson/anonymous are omitted', function () {
    expect(fn () => CreateTherapyDTO::new()->fromArray(['name' => 'A therapy']))
        ->not->toThrow(Throwable::class);
});

test('GroupTherapyDTO::fromArray does not crash when counsellorIds/shareEqually are omitted', function () {
    expect(fn () => GroupTherapyDTO::new()->fromArray(['name' => 'A group therapy']))
        ->not->toThrow(Throwable::class);
});
