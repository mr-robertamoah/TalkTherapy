<?php

namespace App\Http\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminUpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $date = $this->get('dob');

        return [
            'firstName' => ['nullable', 'string'],
            'lastName' => ['nullable', 'string'],
            'otherNames' => ['nullable', 'string'],
            'email' => ['nullable', 'string'],
            'emailVerified' => ['nullable', 'boolean'],
            'dob' => ['nullable', 'date', Rule::prohibitedIf(!!$date && now()->diffInYears(new Carbon($date), true) < 10)],
        ];
    }
}
