<?php

use App\Actions\Request\CreateRequestAction;
use App\Actions\Request\RespondToRequestAction;
use App\DTOs\CreateRequestDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestTypeEnum;
use App\Exceptions\BadRequestException;
use App\Models\User;

// TT-4.10c/SCRUM-292 (security-review finding): a dobChange request can already be created, but
// approving/rejecting it is TT-4.10d's job, not yet built -- this proves the shared respond
// pipeline rejects it explicitly (422) rather than silently falling through every dispatch
// branch and reporting a misleading success while leaving the request untouched.

test('responding to a dobChange request is explicitly rejected, not silently no-opped', function () {
    $ward = User::factory()->create();
    $guardian = User::factory()->create();

    $request = CreateRequestAction::new()->execute(
        CreateRequestDTO::new()->fromArray([
            'from' => $ward,
            'to' => $guardian,
            'for' => $ward,
            'type' => RequestTypeEnum::dobChange->value,
            'data' => ['newDob' => now()->subYears(30)->toDateString(), 'priorDob' => now()->subYears(17)->toDateString()],
        ])
    );

    expect(fn () => RespondToRequestAction::new()->execute(RequestResponseDTO::new()->fromArray([
        'user' => $guardian,
        'response' => 'accepted',
        'request' => $request,
    ])))->toThrow(BadRequestException::class);

    expect($request->fresh()->status)->toBe('PENDING');
});
