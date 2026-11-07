<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class TherapyAssistanceRequest extends FormRequest
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
            // SCRUM-389: refused, not silently cut short (no `exists`: which counsellors exist isn't told).
            'counsellorIds' => ['nullable', 'array', 'max:20'],
            'counsellorIds.*' => ['integer', 'distinct'],
        ];
    }

    // SCRUM-385: name these fields in plain words in the default messages, not "the reply id".
    public function attributes(): array
    {
        return [
            'counsellorIds' => 'counsellors',
        ];
    }
}
