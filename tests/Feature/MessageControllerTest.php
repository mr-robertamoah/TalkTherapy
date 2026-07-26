<?php

use App\Enums\SessionStatusEnum;
use App\Models\Counsellor;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;

// Regression test for SCRUM-20 QA finding: MessageController::returnFailure used to re-throw a
// bare Exception for JSON requests, which lost the original MessageException's 422 code and
// always surfaced to the client as a 500 via Laravel's default exception handler. A rejected
// send now needs to come back as an actual 422 response, not a 500.
test('a non-participant sending a message to a session is rejected with a 422, not a 500', function () {
    $actingUser = User::factory()->create();
    $therapyOwner = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_id' => $therapyOwner->id,
        'addedby_type' => $therapyOwner::class,
        'counsellor_id' => $counsellor->id,
    ]);
    $session = Session::factory()->create([
        'addedby_id' => $counsellor->id,
        'addedby_type' => $counsellor::class,
        'status' => SessionStatusEnum::in_session_confirmation->value,
        'for_id' => $therapy->id,
        'for_type' => $therapy::class,
    ]);

    $response = $this
        ->actingAs($actingUser)
        ->postJson(route('api.messages.create'), [
            'content' => 'hello',
            'type' => 'NORMAL',
            'fromId' => $actingUser->id,
            'fromType' => 'User',
            'forId' => $session->id,
            'forType' => 'Session',
        ]);

    $response->assertStatus(422);
    expect($response->json('message'))->toBe('You are not allowed to create a message for this session.');
});
