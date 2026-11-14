<?php

namespace App\Http\Requests;

use App\Actions\User\EnsureCanUpdateUserByAdminAction;
use App\Models\User;
use App\Rules\MinimumAgeForDobRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminUpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        if (! $this->user()->isAdmin()) {
            return false;
        }

        // SCRUM-425 (security review): refused before validation, so an ordinary admin learns nothing from "already
        // taken". EnsureCanUpdateUserByAdminAction makes the full check.
        $target = $this->has('email') && $this->routeIs('admin.users.update') ? User::find($this->route('userId')) : null;

        return ! $target || $this->user()->isSuperAdmin() || ! EnsureCanUpdateUserByAdminAction::changesEmail($target, $this->input('email'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'firstName' => ['nullable', 'string'],
            'lastName' => ['nullable', 'string'],
            'otherNames' => ['nullable', 'string'],
            // SCRUM-425: a real address, not someone else's (it was only "a string").
            'email' => ['nullable', 'string', 'email', 'max:255', Rule::unique(User::class)->ignore($this->route('userId'))],
            'emailVerified' => ['nullable', 'boolean'],
            'dob' => ['nullable', 'date', new MinimumAgeForDobRule],
        ];
    }
}
