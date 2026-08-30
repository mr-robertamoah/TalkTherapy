<?php

namespace App\Http\Requests;

use App\Enums\OrganizationCounsellorCompensationBasisEnum;
use App\Enums\OrganizationCounsellorCompensationTypeEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateOrganizationCounsellorCompensationRequest extends FormRequest
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
            'type' => ['required', Rule::in(OrganizationCounsellorCompensationTypeEnum::values())],
            'amount' => ['nullable', Rule::requiredIf($this->get('type') === OrganizationCounsellorCompensationTypeEnum::fixed->value), 'integer', 'min:1'],
            // Matches CreateTherapyRequest's own currency validation -- previously just
            // 'string','size:3', which let any 3-letter code through regardless of whether the
            // app actually supports it.
            'currency' => ['nullable', Rule::requiredIf($this->get('type') === OrganizationCounsellorCompensationTypeEnum::fixed->value), 'string', Rule::in(config('currencies.supported'))],
            'percentage' => ['nullable', Rule::requiredIf($this->get('type') === OrganizationCounsellorCompensationTypeEnum::percentage->value), 'integer', 'between:1,100'],
            'basis' => ['nullable', Rule::requiredIf($this->get('type') === OrganizationCounsellorCompensationTypeEnum::percentage->value), Rule::in(OrganizationCounsellorCompensationBasisEnum::values())],
            // SCRUM-146 (TT-6.4c): optional override of the configured default negotiation window.
            'expiryDays' => ['nullable', 'integer', 'between:1,30'],
        ];
    }
}
