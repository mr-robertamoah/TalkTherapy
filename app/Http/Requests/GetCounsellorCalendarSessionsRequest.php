<?php

namespace App\Http\Requests;

use Carbon\Carbon;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class GetCounsellorCalendarSessionsRequest extends FormRequest
{
    // A calendar view has no legitimate reason to span more than a few months -- without a cap
    // this endpoint could be asked to union and eager-load an unbounded number of sessions in one
    // request (security review, SCRUM-212).
    private const MAX_RANGE_DAYS = 93;

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
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date', 'after_or_equal:startDate'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->startDate || ! $this->endDate) {
                return;
            }

            $days = Carbon::parse($this->startDate)->diffInDays(Carbon::parse($this->endDate));

            if ($days > self::MAX_RANGE_DAYS) {
                $validator->errors()->add('endDate', 'The date range must not span more than '.self::MAX_RANGE_DAYS.' days.');
            }
        });
    }
}
