<?php

namespace App\Http\Requests;

use App\Enums\SessionTypeEnum;
use App\Enums\TherapyPerPaymentEnum;
use App\Enums\TherapyTypeEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetCounsellorPricingRequest extends FormRequest
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
            'pricings' => ['required', 'array', 'min:1'],
            'pricings.*.therapyType' => ['nullable', Rule::in(TherapyTypeEnum::values())],
            'pricings.*.sessionType' => ['nullable', Rule::in(SessionTypeEnum::values())],
            'pricings.*.per' => ['nullable', Rule::in(TherapyPerPaymentEnum::values())],
            'pricings.*.amount' => ['required', 'integer', 'min:1'],
            'pricings.*.currency' => ['required', 'string', Rule::in(config('currencies.supported'))],
        ];
    }
}
