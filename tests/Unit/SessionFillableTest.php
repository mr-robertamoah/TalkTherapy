<?php

use App\Models\Session;

// SCRUM-316: which therapy a session belongs to is set only through for()->associate() (by
// CreateSessionAction, and the seeder's seedSession()), never by mass assignment.
test('a session\'s therapy can not be mass-assigned', function () {
    expect((new Session)->getFillable())->not->toContain('for_id')->not->toContain('for_type');
});
