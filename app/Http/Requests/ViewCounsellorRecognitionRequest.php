<?php

namespace App\Http\Requests;

use App\Models\CounsellorRecognition;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

// TT-7.11d1/SCRUM-473: the recognition suggestions for a month. Refused with a 403 before anything is validated unless
// the user is a super administrator (as ManagePlatformFeeWaiverRequest); CounsellorRecognitionService checks again.
// `month` is 'YYYY-MM', no later than the current month (UTC); without one, the previous month -- the one usually
// decided.
class ViewCounsellorRecognitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isSuperAdmin();
    }

    public function rules(): array
    {
        return [
            'month' => ['sometimes', 'string', self::monthRule()],
        ];
    }

    public function month(): string
    {
        return $this->validated('month') ?? now('UTC')->startOfMonth()->subMonth()->format('Y-m');
    }

    public static function monthRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (! CounsellorRecognition::parseMonth($value)) {
                $fail('Choose a month (YYYY-MM) no later than this one.');
            }
        };
    }
}
