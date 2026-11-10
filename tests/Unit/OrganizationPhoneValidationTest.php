<?php

use App\Http\Requests\CreateOrganizationRequest;
use App\Http\Requests\UpdateOrganizationRequest;
use Illuminate\Support\Facades\Validator;

// Previously just 'string','max:255' on both request classes -- a client bypassing (or, as
// happened during this ticket's own manual testing, briefly disabled by) frontend validation
// could save a phone value like "abc". Matches the frontend's own pattern in
// UpdateOrganizationForm.vue.

function organizationPhoneRulesFor(string $requestClass, array $input): array
{
    $request = $requestClass::create('/', 'POST', $input);

    return $request->rules();
}

test('a non-phone-shaped value is rejected on create and update requests', function () {
    foreach ([CreateOrganizationRequest::class, UpdateOrganizationRequest::class] as $requestClass) {
        $input = ['phone' => 'abc'];
        $validator = Validator::make($input, ['phone' => organizationPhoneRulesFor($requestClass, $input)['phone']]);

        expect($validator->fails())->toBeTrue("expected {$requestClass} to reject a non-phone-shaped value");
        expect($validator->errors()->has('phone'))->toBeTrue();
    }
});

test('common real-world phone formats are accepted, including dot-separated and seeded formats', function () {
    foreach ([CreateOrganizationRequest::class, UpdateOrganizationRequest::class] as $requestClass) {
        foreach (['+1-215-747-7329', '270.271.6592', '(215) 747 7329', '+233000000000'] as $phone) {
            $input = ['phone' => $phone];
            $validator = Validator::make($input, ['phone' => organizationPhoneRulesFor($requestClass, $input)['phone']]);

            expect($validator->fails())->toBeFalse("expected {$requestClass} to accept \"{$phone}\"");
        }
    }
});

test('an omitted phone still passes on the create request (nullable)', function () {
    $validator = Validator::make([], ['phone' => organizationPhoneRulesFor(CreateOrganizationRequest::class, [])['phone']]);

    expect($validator->fails())->toBeFalse('expected an omitted phone to pass on CreateOrganizationRequest');
});
