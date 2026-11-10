<?php

use App\Actions\Request\CreateRequestAction;
use App\DTOs\CreateRequestDTO;
use App\Enums\RequestTypeEnum;
use App\Models\User;

// SCRUM-299: exercises the full RequestService::respondToRequest() pipeline through the real HTTP
// endpoint -- proves an ineligible recipient can reject via the actual payload shape the frontend
// sends (`response: 'rejected'`), not just at the RespondToGuardianshipRequestAction unit level.

test('an ineligible recipient can reject a guardianship request via the real HTTP endpoint', function () {
    $ward = User::factory()->create();
    $ineligibleRecipient = User::factory()->create(['dob' => null, 'email_verified_at' => null]);
    $request = CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
        'from' => $ward,
        'to' => $ineligibleRecipient,
        'for' => $ward,
        'type' => RequestTypeEnum::guardianship->value,
    ]));

    $response = $this->actingAs($ineligibleRecipient)->postJson(route('requests.respond', ['requestId' => $request->id]), [
        'response' => 'rejected',
    ]);

    $response->assertSuccessful();
});

test('an ineligible recipient still cannot accept a guardianship request via the real HTTP endpoint', function () {
    $ward = User::factory()->create();
    $ineligibleRecipient = User::factory()->create(['dob' => null, 'email_verified_at' => null]);
    $request = CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
        'from' => $ward,
        'to' => $ineligibleRecipient,
        'for' => $ward,
        'type' => RequestTypeEnum::guardianship->value,
    ]));

    $response = $this->actingAs($ineligibleRecipient)->postJson(route('requests.respond', ['requestId' => $request->id]), [
        'response' => 'accepted',
    ]);

    $response->assertStatus(422);
});
