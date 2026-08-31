<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateUserAvatarRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Self-service only -- this request never carries a target user id, it always operates
        // on $request->user() (see ProfileController::updateAvatar), so the `auth` middleware
        // already guarding this whole route group is the entire authorization surface.
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
            'avatar' => ['nullable', 'file'], // TT-10.8: size/MIME validation, tracked separately
            'deleteAvatar' => ['nullable', 'boolean'],
        ];
    }
}
