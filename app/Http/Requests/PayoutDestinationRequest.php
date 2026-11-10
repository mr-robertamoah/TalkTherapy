<?php

namespace App\Http\Requests;

use App\Enums\PayoutDestinationTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PayoutDestinationRequest extends FormRequest
{
    // Real authorization (verified counsellor only) lives in
    // EnsureCanOnboardPayoutDestinationAction, matching this codebase's existing convention
    // (e.g. SetCounsellorPricingRequest) of a thin FormRequest that only validates shape.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(PayoutDestinationTypeEnum::values())],
            // Real validation of these values happens against Paystack's own bank-resolve API
            // (CreateCounsellorPayoutDestinationAction) -- this is just a cheap local rejection of
            // obviously-malformed input before that round trip (security-engineer finding).
            'accountNumber' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9]+$/'],
            'bankCode' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9]+$/'],
            'currency' => ['required', 'string', Rule::in(config('currencies.supported'))],
        ];
    }
}
