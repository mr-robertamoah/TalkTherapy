<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterOrganizationPaymentInstrumentRequest extends FormRequest
{
    // Real authorization (org admin, verified + consumer-capable org) lives in
    // EnsureCanRegisterOrganizationPaymentInstrumentAction -- mirrors PayoutDestinationRequest's
    // own thin, shape-only convention.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'currency' => ['required', 'string', Rule::in(config('currencies.supported'))],
        ];
    }
}
