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
