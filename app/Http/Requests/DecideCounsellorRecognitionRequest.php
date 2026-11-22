<?php

namespace App\Http\Requests;

// TT-7.11d1/SCRUM-473: approving, skipping or adding a counsellor for a month -- super administrators only (a 403
// before validation). TT-7.11d2/SCRUM-474 opened viewing to any administrator, so deciding has its own authorize().
class DecideCounsellorRecognitionRequest extends ViewCounsellorRecognitionRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isSuperAdmin();
    }

    public function rules(): array
    {
        return [
            'month' => ['required', 'string', self::monthRule()],
            'counsellor_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
