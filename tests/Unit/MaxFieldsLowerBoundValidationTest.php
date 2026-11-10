<?php

use App\Http\Requests\CreateGroupTherapyRequest;
use App\Http\Requests\CreateTherapyRequest;
use App\Http\Requests\UpdateGroupTherapyRequest;
use App\Http\Requests\UpdateTherapyRequest;
use Illuminate\Support\Facades\Validator;

// SCRUM-85: maxUsers/maxCounsellors/maxSessions previously validated only as
// ['nullable', 'integer'] -- 0 and negative values passed straight through to the DB.

function rulesFor(string $requestClass, array $input): array
{
    $request = $requestClass::create('/', 'POST', $input);

    return $request->rules();
}

test('CreateGroupTherapyRequest rejects zero or negative maxUsers/maxCounsellors/maxSessions', function () {
    foreach (['maxUsers', 'maxCounsellors', 'maxSessions'] as $field) {
        foreach ([0, -1] as $value) {
            $input = [$field => $value];
            $validator = Validator::make($input, rulesFor(CreateGroupTherapyRequest::class, $input));

            expect($validator->fails())->toBeTrue("expected {$field}={$value} to fail validation");
            expect($validator->errors()->has($field))->toBeTrue();
        }
    }
});

test('UpdateGroupTherapyRequest rejects zero or negative maxUsers/maxCounsellors/maxSessions', function () {
    foreach (['maxUsers', 'maxCounsellors', 'maxSessions'] as $field) {
        $input = [$field => 0];
        $validator = Validator::make($input, rulesFor(UpdateGroupTherapyRequest::class, $input));

        expect($validator->fails())->toBeTrue("expected {$field}=0 to fail validation");
    }
});

test('CreateTherapyRequest and UpdateTherapyRequest reject zero or negative maxSessions', function () {
    $createValidator = Validator::make(
        ['maxSessions' => 0],
        rulesFor(CreateTherapyRequest::class, ['maxSessions' => 0])
    );
    expect($createValidator->fails())->toBeTrue();

    $updateValidator = Validator::make(
        ['maxSessions' => -5],
        rulesFor(UpdateTherapyRequest::class, ['maxSessions' => -5])
    );
    expect($updateValidator->fails())->toBeTrue();
});

test('a positive maxUsers/maxCounsellors/maxSessions still passes the min:1 rule on every touched request class', function () {
    foreach ([CreateGroupTherapyRequest::class, UpdateGroupTherapyRequest::class] as $requestClass) {
        foreach (['maxUsers', 'maxCounsellors', 'maxSessions'] as $field) {
            $input = [$field => 5];
            $validator = Validator::make($input, [$field => rulesFor($requestClass, $input)[$field]]);

            expect($validator->fails())->toBeFalse("expected {$requestClass}::{$field}=5 to pass validation");
        }
    }

    foreach ([CreateTherapyRequest::class, UpdateTherapyRequest::class] as $requestClass) {
        $input = ['maxSessions' => 5];
        $validator = Validator::make($input, ['maxSessions' => rulesFor($requestClass, $input)['maxSessions']]);

        expect($validator->fails())->toBeFalse("expected {$requestClass}::maxSessions=5 to pass validation");
    }
});

test('an omitted maxUsers/maxCounsellors/maxSessions still passes (nullable)', function () {
    foreach (['maxUsers', 'maxCounsellors', 'maxSessions'] as $field) {
        $validator = Validator::make([], [$field => rulesFor(CreateGroupTherapyRequest::class, [])[$field]]);

        expect($validator->fails())->toBeFalse("expected omitted {$field} to pass validation");
    }
});
