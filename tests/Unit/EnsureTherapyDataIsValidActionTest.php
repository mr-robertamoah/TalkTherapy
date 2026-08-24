<?php

use App\Actions\Therapy\EnsureTherapyDataIsValidAction;
use App\DTOs\CreateTherapyDTO;
use App\DTOs\GroupTherapyDTO;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapySessionTypeEnum;
use App\Exceptions\TherapyCreationDataIsNotValidException;

function aValidCreateTherapyDTO(array $overrides = [])
{
    return CreateTherapyDTO::new()->fromArray(array_merge([
        'public' => true,
        'sessionType' => TherapySessionTypeEnum::once->value,
        'paymentType' => TherapyPaymentTypeEnum::free->value,
    ], $overrides));
}

function aValidGroupTherapyDTO(array $overrides = [])
{
    return GroupTherapyDTO::new()->fromArray(array_merge([
        'public' => true,
        'sessionType' => TherapySessionTypeEnum::once->value,
        'paymentType' => TherapyPaymentTypeEnum::free->value,
    ], $overrides));
}

test('an individual therapy with maxSessions above the sanity ceiling is rejected (SCRUM-84)', function () {
    expect(fn () => EnsureTherapyDataIsValidAction::new()->execute(aValidCreateTherapyDTO(['maxSessions' => 101])))
        ->toThrow(TherapyCreationDataIsNotValidException::class);
});

test('a group therapy with maxSessions above the sanity ceiling is rejected (SCRUM-84)', function () {
    expect(fn () => EnsureTherapyDataIsValidAction::new()->execute(aValidGroupTherapyDTO(['maxSessions' => 101])))
        ->toThrow(TherapyCreationDataIsNotValidException::class);
});

test('maxSessions at the default ceiling is still accepted', function () {
    expect(fn () => EnsureTherapyDataIsValidAction::new()->execute(aValidCreateTherapyDTO(['maxSessions' => 100])))
        ->not->toThrow(TherapyCreationDataIsNotValidException::class);
});

test('an omitted maxSessions on a non-periodic therapy is still accepted', function () {
    expect(fn () => EnsureTherapyDataIsValidAction::new()->execute(aValidCreateTherapyDTO()))
        ->not->toThrow(TherapyCreationDataIsNotValidException::class);
});
