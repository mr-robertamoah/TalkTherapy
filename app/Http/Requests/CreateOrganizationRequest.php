<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CreateOrganizationRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'legalName' => ['nullable', 'string', 'max:255'],
            'registrationNumber' => ['required', 'string', 'max:255', 'unique:organizations,registration_number'],
            'description' => ['nullable', 'string'],
            'email' => ['nullable', 'email', 'max:255'],
            // Matches UpdateOrganizationRequest's own phone rule -- see that class's comment.
            'phone' => ['nullable', 'string', 'max:255', 'regex:/^[0-9+\s().-]{7,20}$/'],
            'isProvider' => ['required', 'boolean'],
            'isConsumer' => ['required', 'boolean'],
        ];
    }
}
