<?php

namespace App\Http\Requests;

use App\Enums\SessionTypeEnum;
use App\Enums\TherapyPaymentTypeEnum;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
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
        $startTime = Carbon::parse($this->get('startTime'))->setTimezone(config('app.timezone'));
        $endTime = Carbon::parse($this->get('endTime'))->setTimezone(config('app.timezone'));
        $now = Carbon::now(config('app.timezone'));

        Log::info('update session request', [$startTime, $endTime, $now, now()]);
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'about' => ['nullable', 'string'],
            'landmark' => ['nullable', 'string'],
            'lat' => ['nullable', Rule::requiredIf($this->get('type') == SessionTypeEnum::in_person->value), 'numeric', 'between:-90,90'],
            'lng' => ['nullable', Rule::requiredIf($this->get('type') == SessionTypeEnum::in_person->value), 'numeric', 'between:-180,180'],
            'startTime' => ['nullable', 'date', Rule::prohibitedIf(
                !($now->addMinutes(30)->lessThanOrEqualTo($startTime))
            )],
            'endTime' => ['nullable', 'date', Rule::prohibitedIf(
                !($startTime->addMinutes(30)->lessThanOrEqualTo($endTime))
            )],
            'cases' => ['nullable', 'array'],
            'topics' => ['nullable', 'array'],
            'paymentType' => ['nullable', Rule::in(TherapyPaymentTypeEnum::values())],
            'type' => ['nullable', Rule::in(SessionTypeEnum::values())],
        ];
    }

    public function messages()
    {
        return [
            'startTime.prohibited_if' => 'The :attribute has to be at least 30 minutes from now.',
            'endTime.prohibited_if' => 'The :attribute has to be at least 30 minutes from the start time.',
        ];
    }
}
