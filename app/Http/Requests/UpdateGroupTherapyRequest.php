<?php

namespace App\Http\Requests;

use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapySessionTypeEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGroupTherapyRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:255'],
            'backgroundStory' => ['nullable', 'string'],
            'anonymous' => ['nullable', 'boolean'],
            'allowInPerson' => ['nullable', 'boolean'],
            'public' => ['nullable', 'boolean'],
            'cases' => ['nullable', 'array'],
            'sessionType' => ['nullable', Rule::in(TherapySessionTypeEnum::values())],
            'paymentType' => ['nullable', Rule::in(TherapyPaymentTypeEnum::values())],
            'maxSessions' => ['nullable', 'integer', 'min:1'],
            'maxUsers' => ['nullable', 'integer', 'min:1'],
            'maxCounsellors' => ['nullable', 'integer', 'min:1'],
            'allowAnyone' => ['nullable', 'boolean'],
            'per' => ['nullable', 'string'],
            'amount' => ['nullable', 'numeric'],
            'inPersonAmount' => ['nullable', 'numeric'],
            'currency' => ['nullable', 'string'],
            'counsellorIds' => ['nullable', 'array'],
        ];
    }
}
