<?php

namespace App\Http\Requests;

use App\Rules\MinimumAgeForDobRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AdminUpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'firstName' => ['nullable', 'string'],
            'lastName' => ['nullable', 'string'],
            'otherNames' => ['nullable', 'string'],
            'email' => ['nullable', 'string'],
            'emailVerified' => ['nullable', 'boolean'],
            'dob' => ['nullable', 'date', new MinimumAgeForDobRule],
        ];
    }
}
