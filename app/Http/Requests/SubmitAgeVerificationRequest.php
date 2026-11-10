<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SubmitAgeVerificationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // TT-4.11b/SCRUM-303: document is deliberately nullable -- the user's own explicit
            // decision was that self-attestation alone must always be sufficient to submit, since
            // requiring a document would be a real barrier to someone who needs help right now.
            'attestation' => ['required', 'string', 'min:10', 'max:2000'],
            'document' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ];
    }
}
