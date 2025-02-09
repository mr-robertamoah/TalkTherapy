<?php

namespace App\Http\Requests;

use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapyPerPaymentEnum;
use App\Enums\TherapySessionTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateGroupTherapyRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'about' => ['required', 'string'],
            'anonymous' => ['required', 'boolean'],
            'allowInPerson' => ['required', 'boolean'],
            'allowAnyone' => ['required', 'boolean'],
            'maxUsers' => ['nullable', 'integer'],
            'maxCounsellors' => ['nullable', 'integer'],
            'public' => ['required', 'boolean'],
            'cases' => ['nullable', 'array'],
            'counsellorIds' => ['nullable', 'array'],
            'sessionType' => ['required', Rule::in(TherapySessionTypeEnum::values())],
            'paymentType' => ['required', Rule::in(TherapyPaymentTypeEnum::values())],
            'maxSessions' => ['nullable', Rule::requiredIf($this->get('sessionType') == TherapySessionTypeEnum::periodic->value), 'integer'],
            'per' => ['nullable', Rule::requiredIf($this->get('paymentType') == TherapyPaymentTypeEnum::paid->value), 'string'],
            'amount' => ['nullable', Rule::requiredIf($this->get('paymentType') == TherapyPaymentTypeEnum::paid->value), 'numeric'],
            'inPersonAmount' => ['nullable', Rule::requiredIf(
                $this->get('paymentType') == TherapyPaymentTypeEnum::paid->value &&
                $this->get('per') == TherapyPerPaymentEnum::session->value &&
                $this->get('allowInPerson')
            ), 'numeric'],
            'currency' => ['nullable', Rule::requiredIf($this->get('paymentType') == TherapyPaymentTypeEnum::paid->value), 'string'],
        ];
    }
}
