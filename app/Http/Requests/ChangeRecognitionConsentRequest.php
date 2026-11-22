<?php

namespace App\Http\Requests;

use App\Services\CounsellorRecognitionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// TT-7.11d1/SCRUM-473: a counsellor withdrawing their featuring consent, or opting back in. Whose recognition it is is
// checked by CounsellorRecognitionService::changeConsent() (anyone else's is "not found").
class ChangeRecognitionConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in([CounsellorRecognitionService::WITHDRAW, CounsellorRecognitionService::RESTORE])],
        ];
    }
}
