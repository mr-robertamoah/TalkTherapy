<?php

namespace App\Http\Requests;

use App\Support\ImageUploadRules;
use Illuminate\Contracts\Validation\ValidationRule;
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'deleteAvatar' => ['nullable', 'boolean'],
            'deleteCover' => ['nullable', 'boolean'],
            // ImageUploadRules is the actual enforcement -- the matching client-side check in
            // ImageUploadField.vue/imageUploadLimits.js is a UX convenience only.
            'avatar' => ImageUploadRules::rules(),
            'cover' => ImageUploadRules::rules(),
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
