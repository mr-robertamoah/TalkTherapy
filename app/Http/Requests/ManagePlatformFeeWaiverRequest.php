<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// TT-7.10b/SCRUM-468: changing a commission-free period ("End now", delete, and -- with its fields -- create). Refused
// with a 403 before anything is validated unless the user is a super administrator, so an ordinary administrator
// learns nothing from the validation; PlatformFeeWaiverService checks again (EnsureIsSuperAdminAction).
class ManagePlatformFeeWaiverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isSuperAdmin();
    }

    public function rules(): array
    {
        return [];
    }
}
