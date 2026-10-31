<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CreateTherapyTopicRequest extends FormRequest
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
            // SCRUM-344 (security review): a former prohibitedIf() here queried the dropped
            // therapy_id column (every topic create 500ed) using a client-chosen `requestId`, before
            // any authorization -- "fixed", it would have let anyone confirm guessed topic names on
            // any therapy. Removed, as SCRUM-339 did for session names.
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'sessions' => ['nullable', 'array'],
        ];
    }
}
