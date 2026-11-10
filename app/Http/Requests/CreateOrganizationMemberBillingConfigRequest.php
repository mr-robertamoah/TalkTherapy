<?php

namespace App\Http\Requests;

use App\Enums\OrganizationMemberBillingModeEnum;
use App\Enums\TherapyPerPaymentEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateOrganizationMemberBillingConfigRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::in(OrganizationMemberBillingModeEnum::values())],
            'per' => ['nullable', Rule::requiredIf($this->get('mode') === OrganizationMemberBillingModeEnum::payPerUse->value), Rule::in(TherapyPerPaymentEnum::values())],
            'includeGroupTherapies' => ['required', 'boolean'],
        ];
    }
}
