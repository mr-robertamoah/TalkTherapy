<?php

namespace App\Http\Requests;

use App\Enums\MessageTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateMessageRequest extends FormRequest
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
            'type' => ['required', Rule::in(MessageTypeEnum::values())],
            'replyId' => ['nullable', 'exists:messages,id'],
            'topicId' => ['nullable', 'exists:therapy_topics,id'],
            'confidential' => ['nullable'],
            'fromId' => ['required', 'integer'],
            'fromType' => ['required', 'string', Rule::in(['Counsellor', 'User'])],
            'toId' => ['nullable', 'integer'],
            'toType' => ['nullable', 'string', Rule::in(['Counsellor', 'User'])],
            'forId' => ['required', 'integer'],
            'forType' => ['required', 'string', Rule::in(['Discussion', 'Session'])],
        ];
    }
}
