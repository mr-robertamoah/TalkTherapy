<?php

namespace App\Http\Requests;

use App\Support\AdminSearch;

// TT-7.11d2/SCRUM-474: the recognition page's "add a counsellor" search, for a month (as the page; last month without
// one) -- super administrators only (a 403 before validation, as DecideCounsellorRecognitionRequest);
// CounsellorRecognitionService checks again.
class SearchRecognitionCounsellorsRequest extends ViewCounsellorRecognitionRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isSuperAdmin();
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'search' => ['required', 'string', 'min:'.AdminSearch::MIN, 'max:'.AdminSearch::MAX],
        ];
    }

    public function search(): string
    {
        return trim($this->validated('search'));
    }
}
