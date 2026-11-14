<?php

namespace App\Http\Requests;

use App\Enums\GenderEnum;
use App\Models\User;
use App\Rules\MinimumAgeForDobRule;
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
            'dob' => ['nullable', 'date', new MinimumAgeForDobRule],
            'gender' => ['nullable', 'string', Rule::in(GenderEnum::values())],
            'country' => ['nullable', 'string', 'max:255'],
            // SCRUM-422: changing the email (adding, replacing or clearing it) needs the current password -- otherwise
            // anyone with a live session could redirect the account's security emails. Ignored when the email stays.
            'current_password' => [Rule::excludeIf(fn () => ! $this->changesEmail()), 'required', 'string', 'current_password'],
        ];
    }

    // Whether the submitted email differs from the saved one -- the same comparison as the controller's isDirty('email')
    // (strings are trimmed and empty ones made null before this runs). Left out entirely, it isn't filled, so unchanged.
    public function changesEmail(): bool
    {
        return $this->has('email') && $this->input('email') !== $this->user()->email;
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'Enter your current password to change your email.',
            'current_password.current_password' => 'That password is not correct.',
        ];
    }
}
