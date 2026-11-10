<?php

use App\Enums\OrganizationCounsellorCompensationTypeEnum;
use App\Enums\TherapyPaymentTypeEnum;
use App\Http\Requests\CreateGroupTherapyRequest;
use App\Http\Requests\CreateOrganizationCounsellorCompensationRequest;
use App\Http\Requests\CreateTherapyRequest;
use App\Http\Requests\UpdateGroupTherapyRequest;
use App\Http\Requests\UpdateTherapyRequest;
use Illuminate\Support\Facades\Validator;

// SCRUM-153 (TT-7.2a): currency was previously an unconstrained 'string' on all four of these
// request classes -- now validated against config('currencies.supported').

function currencyRulesFor(string $requestClass, array $input): array
{
    $request = $requestClass::create('/', 'POST', $input);

    return $request->rules();
}

test('a currency outside the supported list is rejected on create requests', function () {
    foreach ([CreateTherapyRequest::class, CreateGroupTherapyRequest::class] as $requestClass) {
        $input = ['paymentType' => TherapyPaymentTypeEnum::paid->value, 'currency' => 'XYZ'];
        $validator = Validator::make($input, currencyRulesFor($requestClass, $input));

        expect($validator->fails())->toBeTrue("expected {$requestClass} to reject an unsupported currency");
        expect($validator->errors()->has('currency'))->toBeTrue();
    }
});

test('a currency outside the supported list is rejected on update requests', function () {
    foreach ([UpdateTherapyRequest::class, UpdateGroupTherapyRequest::class] as $requestClass) {
        $input = ['currency' => 'XYZ'];
        $validator = Validator::make($input, currencyRulesFor($requestClass, $input));

        expect($validator->fails())->toBeTrue("expected {$requestClass} to reject an unsupported currency");
        expect($validator->errors()->has('currency'))->toBeTrue();
    }
});

test('a currency from the supported list passes on all four request classes', function () {
    $supported = config('currencies.supported');
    expect($supported)->not->toBeEmpty();

    foreach ([CreateTherapyRequest::class, CreateGroupTherapyRequest::class] as $requestClass) {
        $input = ['paymentType' => TherapyPaymentTypeEnum::paid->value, 'currency' => $supported[0]];
        $validator = Validator::make($input, ['currency' => currencyRulesFor($requestClass, $input)['currency']]);

        expect($validator->fails())->toBeFalse("expected {$requestClass} to accept {$supported[0]}");
    }

    foreach ([UpdateTherapyRequest::class, UpdateGroupTherapyRequest::class] as $requestClass) {
        $input = ['currency' => $supported[0]];
        $validator = Validator::make($input, ['currency' => currencyRulesFor($requestClass, $input)['currency']]);

        expect($validator->fails())->toBeFalse("expected {$requestClass} to accept {$supported[0]}");
    }
});

test('an omitted currency still passes on the update requests (nullable)', function () {
    foreach ([UpdateTherapyRequest::class, UpdateGroupTherapyRequest::class] as $requestClass) {
        $validator = Validator::make([], ['currency' => currencyRulesFor($requestClass, [])['currency']]);

        expect($validator->fails())->toBeFalse("expected omitted currency to pass on {$requestClass}");
    }
});

test('the supported currency list is configurable via env, not hardcoded', function () {
    config(['currencies.supported' => ['ABC']]);

    $input = ['paymentType' => TherapyPaymentTypeEnum::paid->value, 'currency' => 'GHS'];
    $validator = Validator::make($input, ['currency' => currencyRulesFor(CreateTherapyRequest::class, $input)['currency']]);

    expect($validator->fails())->toBeTrue('expected GHS to be rejected once the supported list no longer includes it');

    $input = ['paymentType' => TherapyPaymentTypeEnum::paid->value, 'currency' => 'ABC'];
    $validator = Validator::make($input, ['currency' => currencyRulesFor(CreateTherapyRequest::class, $input)['currency']]);

    expect($validator->fails())->toBeFalse('expected ABC to pass once added to the supported list');
});

// SCRUM: CreateOrganizationCounsellorCompensationRequest's currency was previously just
// 'string','size:3' -- now validated against the same config('currencies.supported') list as
// the therapy request classes above (reviewer-flagged gap in coverage for that tightening).
test('a currency outside the supported list is rejected on the compensation request', function () {
    $input = ['type' => OrganizationCounsellorCompensationTypeEnum::fixed->value, 'amount' => 100, 'currency' => 'XYZ'];
    $validator = Validator::make($input, currencyRulesFor(CreateOrganizationCounsellorCompensationRequest::class, $input));

    expect($validator->fails())->toBeTrue('expected the compensation request to reject an unsupported currency');
    expect($validator->errors()->has('currency'))->toBeTrue();
});

test('a currency from the supported list passes on the compensation request', function () {
    $supported = config('currencies.supported');
    $input = ['type' => OrganizationCounsellorCompensationTypeEnum::fixed->value, 'amount' => 100, 'currency' => $supported[0]];
    $validator = Validator::make($input, currencyRulesFor(CreateOrganizationCounsellorCompensationRequest::class, $input));

    expect($validator->fails())->toBeFalse("expected the compensation request to accept {$supported[0]}");
});
