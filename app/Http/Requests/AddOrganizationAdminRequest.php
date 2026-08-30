<?php

namespace App\Http\Requests;

use App\Enums\OrganizationAdminRoleEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddOrganizationAdminRequest extends FormRequest
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
            // No 'exists:users,id' here (SCRUM-176): that check would run before, not after,
            // EnsureUserIsOrganizationOwnerAction in OrganizationAdminService::addAdmin(),
            // letting a non-owner distinguish a real userId (422→service error) from a fake one
            // (422 here) -- a mild existence oracle. EnsureOrganizationAdminTargetExistsAction
            // already re-checks existence in the service chain, but only after the owner check,
            // so a non-owner gets the same 403 either way; an owner still gets that action's own
            // 404 for a genuinely missing user.
            'userId' => ['required', 'integer'],
            'role' => ['sometimes', Rule::in(OrganizationAdminRoleEnum::values())],
        ];
    }
}
