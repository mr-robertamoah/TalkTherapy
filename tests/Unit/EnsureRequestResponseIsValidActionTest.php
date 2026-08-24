<?php

use App\Actions\Request\EnsureRequestResponseIsValidAction;
use App\DTOs\RequestResponseDTO;
use App\Exceptions\BadRequestException;

test('a garbage response value is rejected (SCRUM-89)', function () {
    $dto = RequestResponseDTO::new()->fromArray(['response' => 'MAYBE']);

    expect(fn () => EnsureRequestResponseIsValidAction::new()->execute($dto))
        ->toThrow(BadRequestException::class);
});

test('accepted and rejected are valid regardless of case', function () {
    foreach (['accepted', 'ACCEPTED', 'Accepted', 'rejected', 'REJECTED', 'Rejected'] as $response) {
        $dto = RequestResponseDTO::new()->fromArray(['response' => $response]);

        expect(fn () => EnsureRequestResponseIsValidAction::new()->execute($dto))
            ->not->toThrow(BadRequestException::class);
    }
});

test('a null response is valid (every RespondTo*RequestAction treats it as an implicit rejection)', function () {
    $dto = RequestResponseDTO::new()->fromArray(['response' => null]);

    expect(fn () => EnsureRequestResponseIsValidAction::new()->execute($dto))
        ->not->toThrow(BadRequestException::class);
});
