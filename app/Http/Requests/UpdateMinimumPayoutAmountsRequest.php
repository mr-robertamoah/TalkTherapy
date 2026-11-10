<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMinimumPayoutAmountsRequest extends FormRequest
{
    // Real authorization (super admin only) lives in UpdateSettingAction via
    // EnsureIsSuperAdminAction -- matches this codebase's thin-FormRequest convention.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // The frontend always submits the full set of supported currencies together (never a
            // partial subset) -- SettingsEnum::minimumPayoutAmount is stored as one JSON blob per
            // key, so a partial submit would silently wipe out any currency left off the request.
            // `size` alone doesn't guarantee N *distinct* currencies -- without `distinct` here, a
            // same-size payload repeating one currency (e.g. two GHS rows, no USD) would pass this
            // check while still silently dropping USD's stored threshold (security-engineer finding).
            'amounts' => ['required', 'array', 'size:'.count(config('currencies.supported'))],
            'amounts.*.currency' => ['required', 'string', 'distinct', Rule::in(config('currencies.supported'))],
            'amounts.*.amount' => ['required', 'numeric', 'min:0'],
        ];
    }
}
