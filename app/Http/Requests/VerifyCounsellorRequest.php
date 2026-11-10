<?php

namespace App\Http\Requests;

use App\Enums\LicensingTypeEnum;
use App\Models\LicensingAuthority;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            //
            // SCRUM-301: a counsellor can't be verified with no documentation. The licensing authority is
            // required, and its licence in exactly the form that authority issues it (license_type):
            // a copy of the document (FILE), its number (NUMBER), or both (BOTH) -- the same parts the
            // form shows for it. The national ID by its number or a scan (the either-or the form already
            // asked for). Before, every field was optional here and only the browser checked anything.
            'licenseFile' => [Rule::requiredIf(fn () => in_array($this->licenseType(), [LicensingTypeEnum::file->value, LicensingTypeEnum::both->value], true)), 'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'nationalIdFile' => ['nullable', 'required_without:nationalIdNumber', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'licenseNumber' => [Rule::requiredIf(fn () => in_array($this->licenseType(), [LicensingTypeEnum::number->value, LicensingTypeEnum::both->value], true)), 'nullable', 'string', 'max:255'],
            'nationalIdNumber' => ['nullable', 'required_without:nationalIdFile', 'string', 'max:255'],
            'licensingAuthorityId' => ['required', 'int', 'exists:licensing_authorities,id'],
        ];
    }

    private function licenseType(): ?string
    {
        $id = $this->input('licensingAuthorityId');

        return is_numeric($id) ? LicensingAuthority::query()->whereKey((int) $id)->value('license_type') : null;
    }

    // Rule::requiredIf() reports as plain `required`, so those are the keys.
    public function messages(): array
    {
        return [
            'licenseFile.required' => 'Upload a copy of your licence (an image or a PDF).',
            'licenseNumber.required' => 'Enter your licence number.',
            'licensingAuthorityId.required' => 'Select the licensing authority that issued your licence.',
            'nationalIdFile.required_without' => 'Enter your national ID number or upload a copy of the ID.',
            'nationalIdNumber.required_without' => 'Enter your national ID number or upload a copy of the ID.',
        ];
    }

    // SCRUM-385: name these fields in plain words in the default messages, not "the reply id".
    public function attributes(): array
    {
        return [
            'licensingAuthorityId' => 'licensing authority',
        ];
    }
}
