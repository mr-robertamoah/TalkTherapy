<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCounsellorRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'deleteAvatar' => ['nullable', 'boolean'],
            'deleteCover' => ['nullable', 'boolean'],
            'avatar' => ['nullable', 'file'], // TODO validate size of files
            'cover' => ['nullable', 'file'],
            'name' => ['nullable', 'string', 'max:255'],
            'about' => ['nullable', 'string'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:13'],
            'selectedCases' => ['nullable', 'array'],
            'selectedLanguages' => ['nullable', 'array'],
            'selectedReligions' => ['nullable', 'array'],
            'professionId' => ['nullable', 'integer'],
            'contactVisible' => ['nullable', 'bool'],
        ];
    }
}
