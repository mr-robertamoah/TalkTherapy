<?php

namespace App\Http\Requests;

use App\Enums\LicensingAuthorityTypeEnum;
use App\Enums\LicensingTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateLicensingAuthorityRequest extends FormRequest
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
            'name' => ['required', 'string', 'unique:licensing_authorities,name'],
            'about' => ['nullable', 'string'],
            'type' => ['required', 'string', Rule::in(LicensingAuthorityTypeEnum::values())],
            'licenseType' => ['required', 'string', Rule::in(LicensingTypeEnum::values())],
            'country' => ['nullable', 'string'],
            'other' => [Rule::requiredIf($this->type == LicensingAuthorityTypeEnum::other->value), 'nullable', 'string'],
            'phone' => ['required', 'string'],
            'email' => ['required', 'string'],
            'addedbyType' => ['nullable', 'string'],
            'addedbyId' => ['nullable', 'integer'],
        ];
    }
}
