<?php

namespace App\Http\Requests;

use App\Enums\GenderEnum;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        return [
            'firstName' => ['nullable', 'string', 'max:255'],
            'lastName' => ['nullable', 'string', 'max:255'],
            'otherNames' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255', Rule::unique(User::class)->ignore($this->user()->id)],
            'dob' => ['nullable', 'date', 'max:255'],
            'gender' => ['nullable', 'string', 'max:255', Rule::in(GenderEnum::values())],
            'country' => ['nullable', 'string', 'max:255'],
        ];
    }
}
