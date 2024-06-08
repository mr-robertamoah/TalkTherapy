<?php

namespace App\Http\Requests;

use App\Enums\SessionTypeEnum;
use App\Enums\TherapyPaymentTypeEnum;
use App\Models\Session;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateSessionRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255', Rule::prohibitedIf(Session::query()->whereTherapyId($this->get('requestId'))->whereName($this->get('name'))->exists())],
            'about' => ['required', 'string'],
            'landmark' => ['nullable', 'string'],
            'lat' => ['nullable', Rule::requiredIf($this->get('type') == SessionTypeEnum::in_person->value), 'numeric', 'between:-90,90'],
            'lng' => ['nullable', Rule::requiredIf($this->get('type') == SessionTypeEnum::in_person->value), 'numeric', 'between:-180,180'],
            'startTime' => ['required', 'date', Rule::prohibitedIf(
                !(now()->addMinutes(30)->lessThanOrEqualTo((new Carbon($this->get('startTime')))->utc())
            ))],
            'endTime' => ['required', 'date', Rule::prohibitedIf(
                !((new Carbon($this->get('startTime')))->utc()->addMinutes(30)->lessThanOrEqualTo(new Carbon($this->get('endTime'))))
            )],
            'cases' => ['nullable', 'array'],
            'topics' => ['nullable', 'array'],
            'paymentType' => ['required', Rule::in(TherapyPaymentTypeEnum::values())],
            'type' => ['required', Rule::in(SessionTypeEnum::values())],
        ];
    }
}
