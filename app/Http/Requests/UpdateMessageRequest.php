<?php

namespace App\Http\Requests;

use App\Enums\MessageTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMessageRequest extends FormRequest
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
            'content' => ['nullable', 'string'],
            'deletedFiles' => ['nullable', 'array'],
            'type' => ['nullable', Rule::in(MessageTypeEnum::values())],
            'replyId' => ['nullable', 'exists:messages,id'],
            'topicId' => ['nullable', 'exists:therapy_topics,id'],
            'confidential' => ['nullable'],
        ];
    }
}
