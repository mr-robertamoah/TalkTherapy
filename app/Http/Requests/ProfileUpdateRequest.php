<?php

namespace App\Http\Requests;

use App\Enums\GenderEnum;
use App\Models\User;
use Carbon\Carbon;
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
        $date = $this->get('dob');

        return [
            'firstName' => ['nullable', 'string', 'max:255'],
            'lastName' => ['nullable', 'string', 'max:255'],
            'otherNames' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255', Rule::unique(User::class)->ignore($this->user()->id)],
            'dob' => ['nullable', 'date', Rule::prohibitedIf(!!$date && now()->diffInYears(new Carbon($date), true) < 10)],
            'gender' => ['nullable', 'string', Rule::in(GenderEnum::values())],
            'country' => ['nullable', 'string', 'max:255'],
        ];
    }
}
