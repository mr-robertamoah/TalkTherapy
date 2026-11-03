<?php

namespace App\Http\Requests;

use App\Enums\SessionTypeEnum;
use App\Enums\TherapyPaymentTypeEnum;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\ValidationRule;
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $startTime = Carbon::parse($this->get('startTime'))->setTimezone(config('app.timezone'));
        $endTime = Carbon::parse($this->get('endTime'))->setTimezone(config('app.timezone'));
        $now = Carbon::now(config('app.timezone'));

        return [
            // SCRUM-339: a former prohibitedIf() here checked name uniqueness against a client-chosen
            // `requestId` (never sent by the app, so it never applied to real requests) -- usable
            // only to confirm guessed session names of any therapy, before any authorization.
            'name' => ['required', 'string', 'max:255'],
            'about' => ['required', 'string'],
            'landmark' => ['nullable', 'string'],
            'lat' => ['nullable', Rule::requiredIf($this->get('type') == SessionTypeEnum::in_person->value), 'numeric', 'between:-90,90'],
            'lng' => ['nullable', Rule::requiredIf($this->get('type') == SessionTypeEnum::in_person->value), 'numeric', 'between:-180,180'],
            'startTime' => ['required', 'date', Rule::prohibitedIf(
                ! ($now->copy()->addMinutes(30)->lessThanOrEqualTo($startTime))
            )],
            'endTime' => ['required', 'date', Rule::prohibitedIf(
                ! ($startTime->copy()->addMinutes(30)->lessThanOrEqualTo($endTime))
            )],
            'cases' => ['nullable', 'array'],
            'topics' => ['nullable', 'array'],
            'paymentType' => ['required', Rule::in(TherapyPaymentTypeEnum::values())],
            'type' => ['required', Rule::in(SessionTypeEnum::values())],
        ];
    }

    // SCRUM-377: Rule::prohibitedIf() applies the plain `prohibited` rule, so the keys are
    // `*.prohibited`; the old `*.prohibited_if` keys never matched and Laravel's default showed.
    public function messages()
    {
        return [
            'startTime.prohibited' => 'The :attribute has to be at least 30 minutes from now.',
            'endTime.prohibited' => 'The :attribute has to be at least 30 minutes from the start time.',
        ];
    }
}
