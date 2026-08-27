<?php

namespace App\Http\Requests;

use App\Enums\SessionTypeEnum;
use App\Enums\TherapyPaymentTypeEnum;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\ValidationRule;
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
     * Normalize blank startTime/endTime to null so every downstream consumer of
     * $request->startTime/endTime (this class's own rules(), and SessionController's direct
     * property access feeding CreateSessionDTO/EnsureSessionDataIsValidAction) sees either a
     * real value or null, never a blank string that Carbon::parse() would silently turn into
     * "now" (SCRUM-128).
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'startTime' => $this->filled('startTime') ? $this->get('startTime') : null,
            'endTime' => $this->filled('endTime') ? $this->get('endTime') : null,
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $startTime = $this->filled('startTime') ? Carbon::parse($this->get('startTime'))->setTimezone(config('app.timezone')) : null;
        $endTime = $this->filled('endTime') ? Carbon::parse($this->get('endTime'))->setTimezone(config('app.timezone')) : null;
        $now = Carbon::now(config('app.timezone'));

        return [
            'name' => ['nullable', 'string', 'max:255'],
            'about' => ['nullable', 'string'],
            'landmark' => ['nullable', 'string'],
            'lat' => ['nullable', Rule::requiredIf($this->get('type') == SessionTypeEnum::in_person->value), 'numeric', 'between:-90,90'],
            'lng' => ['nullable', Rule::requiredIf($this->get('type') == SessionTypeEnum::in_person->value), 'numeric', 'between:-180,180'],
            'startTime' => ['nullable', 'date', Rule::prohibitedIf(
                $startTime !== null && ! $now->copy()->addMinutes(30)->lessThanOrEqualTo($startTime)
            )],
            'endTime' => ['nullable', 'date', Rule::prohibitedIf(
                $endTime !== null && $startTime !== null && ! $startTime->copy()->addMinutes(30)->lessThanOrEqualTo($endTime)
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
