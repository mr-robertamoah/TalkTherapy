<?php

namespace App\Http\Requests;

use App\Enums\SessionAttendanceStatusEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// SCRUM-445: the register, by key (see GetSessionAttendanceRosterAction) -- never a user id. At most one entry per
// person a group can hold (its members, plus a creator), each key once.
class RecordSessionAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'attendance' => ['required', 'array', 'list', 'min:1', 'max:'.(config('limits.group_therapy_max_users') + 1)],
            'attendance.*' => ['required', 'array:key,status'],
            'attendance.*.key' => ['required', 'string', 'max:20', 'distinct'],
            'attendance.*.status' => ['required', 'string', Rule::enum(SessionAttendanceStatusEnum::class)],
        ];
    }
}
