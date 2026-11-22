<?php

namespace App\Http\Requests;

// TT-7.11d1/SCRUM-473: approving, skipping or adding a counsellor for a month -- super administrators only (a 403
// before validation, from ViewCounsellorRecognitionRequest::authorize()).
class DecideCounsellorRecognitionRequest extends ViewCounsellorRecognitionRequest
{
    public function rules(): array
    {
        return [
            'month' => ['required', 'string', self::monthRule()],
            'counsellor_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
