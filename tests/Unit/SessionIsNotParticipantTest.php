<?php

use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;

// TT-3.1b/SCRUM-275 security-review finding: Session::isNotParticipant() used to be
// `$this->for?->isNotParticipant($user)`, which returns null (falsy) rather than true when `for`
// doesn't resolve (an orphaned/soft-deleted parent, a for_type/for_id mismatch, or
// SessionFactory's own default `for_id => 1` with no matching row) -- fail-OPEN for any caller
// doing `if ($session->isNotParticipant($user)) { deny }`, since a missing `for` would silently
// authorize everyone instead of no one. This was newly exposed by TT-3.1b wiring authorization
// checks in LeaveVideoSessionAction/EndVideoSessionAction directly onto this method.

test('isNotParticipant returns true, not null, when the session\'s parent cannot be resolved', function () {
    $session = Session::factory()->create(['for_id' => 999999, 'for_type' => Therapy::class]);
    $user = User::factory()->create();

    expect($session->isNotParticipant($user))->toBeTrue();
});

test('isParticipant returns false, not null, when the session\'s parent cannot be resolved', function () {
    $session = Session::factory()->create(['for_id' => 999999, 'for_type' => Therapy::class]);
    $user = User::factory()->create();

    expect($session->isParticipant($user))->toBeFalse();
});

test('isNotParticipant is still false for a real participant of a resolvable session', function () {
    $client = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);

    expect($session->isNotParticipant($client))->toBeFalse();
});

test('isNotParticipant is still true for a genuine non-participant of a resolvable session', function () {
    $client = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);
    $outsider = User::factory()->create();

    expect($session->isNotParticipant($outsider))->toBeTrue();
});
