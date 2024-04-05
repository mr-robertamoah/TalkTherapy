<?php

namespace App\Http\Requests;

use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapySessionTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTherapyRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
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
            'maxSessions' => ['nullable', 'integer'],
            'per' => ['nullable', 'string'],
            'amount' => ['nullable', 'numeric'],
            'inPersonAmount' => ['nullable', 'numeric'],
            'currency' => ['nullable', 'string'],
        ];
    }
}
