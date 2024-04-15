<?php

namespace App\Http\Requests;

use App\Enums\SessionTypeEnum;
use App\Enums\TherapyPaymentTypeEnum;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSessionRequest extends FormRequest
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
            'about' => ['nullable', 'string'],
            'landmark' => ['nullable', 'string'],
            'lat' => ['nullable', Rule::requiredIf($this->get('type') == SessionTypeEnum::in_person->value), 'numeric', 'between:-90,90'],
            'lng' => ['nullable', Rule::requiredIf($this->get('type') == SessionTypeEnum::in_person->value), 'numeric', 'between:-180,180'],
            'startTime' => ['nullable', 'date', Rule::prohibitedIf(
                !(now()->addMinutes(30)->lessThanOrEqualTo(new Carbon($this->get('startTime')))
            ))],
            'endTime' => ['nullable', 'date', Rule::prohibitedIf(
                !((new Carbon($this->get('startTime')))->addMinutes(30)->lessThanOrEqualTo(new Carbon($this->get('endTime'))))
            )],
            'cases' => ['nullable', 'array'],
            'topics' => ['nullable', 'array'],
            'paymentType' => ['nullable', Rule::in(TherapyPaymentTypeEnum::values())],
            'type' => ['nullable', Rule::in(SessionTypeEnum::values())],
        ];
    }
}
