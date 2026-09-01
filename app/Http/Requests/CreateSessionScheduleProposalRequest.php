<?php

namespace App\Http\Requests;

use App\Enums\SessionTypeEnum;
use App\Enums\TherapyPaymentTypeEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateSessionScheduleProposalRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:255'],
            // Matches CreateSessionRequest's identical fields -- this data is persisted verbatim
            // into requests.data and is what TT-2.5b's accept step will eventually feed straight
            // into CreateSessionAction, so it must be valid now, not just well-typed.
            'type' => ['nullable', Rule::in(SessionTypeEnum::values())],
            'paymentType' => ['nullable', Rule::in(TherapyPaymentTypeEnum::values())],
            'expiryDays' => ['nullable', 'integer', 'between:1,30'],
        ];
    }
}
