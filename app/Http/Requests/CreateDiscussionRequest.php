<?php

namespace App\Http\Requests;

use Carbon\Carbon;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateDiscussionRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // SCRUM-471: as CreateSessionRequest -- a discussion starts at least 30 minutes from now and ends at least 30
        // minutes after it starts, so one can't be created already over (and ended at once, for its stars).
        $startTime = Carbon::parse($this->get('startTime'))->setTimezone(config('app.timezone'));
        $endTime = Carbon::parse($this->get('endTime'))->setTimezone(config('app.timezone'));
        $now = Carbon::now(config('app.timezone'));

        return [
            'name' => ['required', 'string'],
            'description' => ['required', 'string'],
            'maxCounsellors' => ['nullable', 'integer', 'min:1'],
            'startTime' => ['required', 'date', Rule::prohibitedIf(
                ! ($now->copy()->addMinutes(30)->lessThanOrEqualTo($startTime))
            )],
            'endTime' => ['required', 'date', Rule::prohibitedIf(
                ! ($startTime->copy()->addMinutes(30)->lessThanOrEqualTo($endTime))
            )],
        ];
    }

    public function messages()
    {
        return [
            'startTime.prohibited' => 'The :attribute has to be at least 30 minutes from now.',
            'endTime.prohibited' => 'The :attribute has to be at least 30 minutes from the start time.',
        ];
    }
}
