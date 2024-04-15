<?php

namespace App\Http\Requests;

use App\Models\TherapyTopic;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::prohibitedIf(TherapyTopic::query()->whereTherapyId($this->get('requestId'))->whereName($this->get('name'))->exists())],
            'description' => ['required', 'string'],
            'sessions' => ['nullable', 'array']
        ];
    }
}
