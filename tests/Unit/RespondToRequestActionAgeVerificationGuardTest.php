<?php

use App\Actions\Request\CreateRequestAction;
use App\Actions\Request\RespondToRequestAction;
use App\DTOs\CreateRequestDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestTypeEnum;
use App\Exceptions\BadRequestException;
use App\Models\Administrator;
use App\Models\User;

// TT-4.11b/SCRUM-303: an ageVerification request can already be created (SubmitAgeVerificationAction),
// but approving/rejecting it is TT-4.11c's job, not yet built -- this proves the shared respond
// pipeline rejects it explicitly (422) rather than silently falling through every dispatch branch
// and reporting a misleading success while leaving the request untouched. Mirrors the identical,
// since-removed dobChange-era guard test (TT-4.10c/SCRUM-292).

test('responding to an ageVerification request is explicitly rejected, not silently no-opped', function () {
    $user = User::factory()->create();
    $admin = User::factory()->has(Administrator::factory())->create();

    $request = CreateRequestAction::new()->execute(
        CreateRequestDTO::new()->fromArray([
            'from' => $user,
            'to' => null,
            'for' => $user,
            'type' => RequestTypeEnum::ageVerification->value,
            'data' => ['attestation' => 'I am an adult.'],
        ])
    );

    expect(fn () => RespondToRequestAction::new()->execute(RequestResponseDTO::new()->fromArray([
        'user' => $admin,
        'response' => 'accepted',
        'request' => $request,
    ])))->toThrow(BadRequestException::class);

    expect($request->fresh()->status)->toBe('PENDING');
});
