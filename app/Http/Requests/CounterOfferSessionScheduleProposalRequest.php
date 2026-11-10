<?php

namespace App\Http\Requests;

use App\Enums\SessionTypeEnum;
use App\Enums\TherapyPaymentTypeEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CounterOfferSessionScheduleProposalRequest extends FormRequest
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
            'startTime' => ['required', 'date'],
            'endTime' => ['required', 'date'],
            // Unlike a fresh proposal, a counter-offer only has to change the time -- everything
            // else falls back to the proposal it's superseding (CounterOfferSessionScheduleProposalAction),
            // so these stay optional here rather than reusing CreateSessionScheduleProposalRequest's
            // required 'about'.
            'name' => ['nullable', 'string', 'max:255'],
            'about' => ['nullable', 'string'],
            'type' => ['nullable', Rule::in(SessionTypeEnum::values())],
            'paymentType' => ['nullable', Rule::in(TherapyPaymentTypeEnum::values())],
            'expiryDays' => ['nullable', 'integer', 'between:1,30'],
        ];
    }
}
