<?php

use App\Enums\SessionTypeEnum;
use App\Models\Counsellor;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;

// Regression test for SCRUM-128: UpdateSessionRequest::rules() called Carbon::parse($this->get(...))
// unconditionally, even when startTime/endTime were absent from the request. Carbon::parse(null)
// returns "now", not null, so a partial update that omitted both fields still tripped the
// prohibited_if 30-minutes-apart comparisons against that fabricated "now" value.

test('partially updating a session without startTime/endTime does not trip the date validation', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapyOwner = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $therapyOwner->id,
        'counsellor_id' => $counsellor->id,
    ]);
    $session = Session::factory()->create([
        'addedby_type' => Counsellor::class,
        'addedby_id' => $counsellor->id,
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'type' => SessionTypeEnum::online->value,
        'start_time' => now()->addHours(2),
        'end_time' => now()->addHours(3),
    ]);

    $this->actingAs($counsellorUser);

    $response = $this->patchJson("/therapies/{$therapy->id}/sessions/{$session->id}", [
        'name' => 'Renamed without touching times',
    ]);

    $response->assertOk();
    expect($session->refresh()->name)->toBe('Renamed without touching times');
});

test('partially updating only startTime still validates it against the session\'s existing endTime', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapyOwner = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $therapyOwner->id,
        'counsellor_id' => $counsellor->id,
    ]);
    $session = Session::factory()->create([
        'addedby_type' => Counsellor::class,
        'addedby_id' => $counsellor->id,
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'type' => SessionTypeEnum::online->value,
        'start_time' => now()->addHours(2),
        'end_time' => now()->addHours(3),
    ]);

    $this->actingAs($counsellorUser);

    // Only startTime is submitted; endTime is genuinely omitted, so EnsureSessionDataIsValidAction
    // must fall back to the session's existing end_time (now()+3h) rather than skipping the check
    // outright -- this new startTime is only 10 minutes before that, well under the 30-minute rule.
    $response = $this->patchJson("/therapies/{$therapy->id}/sessions/{$session->id}", [
        'startTime' => now()->addHours(2)->addMinutes(50),
    ]);

    $response->assertStatus(422);
    $response->assertJson(['message' => 'The end time must be at least 30 minutes from the start time.']);
});

test('a blank startTime/endTime string is treated the same as an omitted field', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapyOwner = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $therapyOwner->id,
        'counsellor_id' => $counsellor->id,
    ]);
    $session = Session::factory()->create([
        'addedby_type' => Counsellor::class,
        'addedby_id' => $counsellor->id,
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'type' => SessionTypeEnum::online->value,
        'start_time' => now()->addHours(2),
        'end_time' => now()->addHours(3),
    ]);

    $this->actingAs($counsellorUser);

    // Carbon::parse('') resolves to "now", same as Carbon::parse(null) -- a blank string must be
    // normalized the same way an omitted field is, or it slips past filled()-based guards while
    // still fabricating a fake "now" downstream.
    $response = $this->patchJson("/therapies/{$therapy->id}/sessions/{$session->id}", [
        'name' => 'Renamed with blank time strings',
        'startTime' => '',
        'endTime' => '',
    ]);

    $response->assertOk();
    $session->refresh();
    expect($session->name)->toBe('Renamed with blank time strings');
    // Confirms start_time was left as its originally-scheduled value (~2h from now), not
    // silently overwritten with "now" -- which is what Carbon::parse('') would have produced.
    expect($session->start_time->diffInMinutes(now()->addHours(2), true))->toBeLessThan(1);
});
