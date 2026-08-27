<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationRequest extends FormRequest
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
        // Deliberately no 'nullable' on legalName/description/email/phone: UpdateOrganizationAction
        // skips any field that resolves to null (it can't distinguish "omitted" from "explicitly
        // cleared"), so advertising null-clearing support here would be a contract this ticket
        // doesn't actually honor. Clearing these fields is a separate follow-up if ever needed.
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'legalName' => ['sometimes', 'string', 'max:255'],
            'registrationNumber' => ['sometimes', 'string', 'max:255', Rule::unique('organizations', 'registration_number')->ignore($this->route('organizationId'))],
            'description' => ['sometimes', 'string'],
            'email' => ['sometimes', 'email', 'max:255'],
            'phone' => ['sometimes', 'string', 'max:255'],
            'isProvider' => ['sometimes', 'boolean'],
            'isConsumer' => ['sometimes', 'boolean'],
            'selfApplyEnabled' => ['sometimes', 'boolean'],
        ];
    }
}
