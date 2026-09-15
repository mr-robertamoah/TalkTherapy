<?php

use App\Models\GroupTherapy;
use App\Models\User;

// TT-3.2f-b/SCRUM-319: memberIsMinor() answers a distinct question from clientIsMinor() (which is
// scoped to the group's own creator) -- these tests cover its own snapshot-vs-fallback behavior.

test('memberIsMinor reads the was_minor_at_join snapshot when present', function () {
    $creator = User::factory()->adult()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
    ]);
    $member = User::factory()->adult()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false, 'was_minor_at_join' => true]);

    expect($groupTherapy->memberIsMinor($member))->toBeTrue();
});

test('memberIsMinor reads a false snapshot correctly, not just falling back on it being falsy', function () {
    $creator = User::factory()->adult()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
    ]);
    // A currently-minor user whose OWN recorded snapshot says they were an adult at join time
    // (e.g. their dob has since been edited) -- the snapshot must win over the live check.
    $member = User::factory()->create(['dob' => now()->subYears(15)]);
    $groupTherapy->users()->attach($member->id, ['anonymous' => false, 'was_minor_at_join' => false]);

    expect($groupTherapy->memberIsMinor($member))->toBeFalse();
});

test('memberIsMinor falls back to a live isAdult() check when the snapshot is null (a pre-existing row)', function () {
    $creator = User::factory()->adult()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
    ]);
    $minorMember = User::factory()->create(['dob' => now()->subYears(15)]);
    // Deliberately omits was_minor_at_join, simulating a membership row that predates this column.
    $groupTherapy->users()->attach($minorMember->id, ['anonymous' => false]);

    expect($groupTherapy->memberIsMinor($minorMember))->toBeTrue();
});

test('memberIsMinor falls back to a live isAdult() check for a non-member entirely (no pivot row at all)', function () {
    $creator = User::factory()->adult()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
    ]);
    $minorOutsider = User::factory()->create(['dob' => now()->subYears(15)]);
    $adultOutsider = User::factory()->adult()->create();

    expect($groupTherapy->memberIsMinor($minorOutsider))->toBeTrue()
        ->and($groupTherapy->memberIsMinor($adultOutsider))->toBeFalse();
});
