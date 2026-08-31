<?php

namespace App\Http\Requests;

use App\Support\ImageUploadRules;
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
            // ImageUploadRules is the actual enforcement -- the matching client-side check in
            // ImageUploadField.vue/imageUploadLimits.js is a UX convenience only.
            'avatar' => ImageUploadRules::rules(),
            'deleteAvatar' => ['nullable', 'boolean'],
        ];
    }
}
