<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class VerifyCounsellorRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user->counsellor || $user->isAdmin();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // SCRUM-300 (security review): identity scans only -- the same types and cap as the
            // age-verification upload, never arbitrary files (HTML/SVG served back to an admin).
            'licenseFile' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'nationalIdFile' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'licenseNumber' => ['nullable', 'string'],
            'nationalIdNumber' => ['nullable', 'string'],
            'licensingAuthorityId' => ['nullable', 'int', 'exists:licensing_authorities,id'],
        ];
    }
}
