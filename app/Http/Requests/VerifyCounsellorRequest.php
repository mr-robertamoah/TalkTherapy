<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyCounsellorRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        return ($user->counsellor || $user->isAdmin());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'licenseFile' => ['nullable', 'file'],
            'nationalIdFile' => ['nullable', 'file'],
            'licenseNumber' => ['nullable', 'string'],
            'nationalIdNumber' => ['nullable', 'string'],
            'licensingAuthorityId' => ['nullable', 'int', 'exists:licensing_authorities,id'],
        ];
    }
}
