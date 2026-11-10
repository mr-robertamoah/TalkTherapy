<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePlatformFeeRequest extends FormRequest
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
            'percentage' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
