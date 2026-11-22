<?php

namespace App\Http\Requests;

use App\Models\CounsellorRecognition;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

// TT-7.11d1/SCRUM-473: the recognition suggestions for a month. TT-7.11d2/SCRUM-474: any administrator may view them
// (the admin page is read-only for an ordinary one); deciding stays super-admin only (DecideCounsellorRecognitionRequest).
// Anyone else is refused with a 403 before anything is validated; CounsellorRecognitionService checks again.
// `month` is 'YYYY-MM', the current UTC month or the previous one (CounsellorRecognition::parseMonth()); without one,
// the previous month -- the one usually decided.
class ViewCounsellorRecognitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    public function rules(): array
    {
        return [
            'month' => ['sometimes', 'string', self::monthRule()],
        ];
    }

    public function month(): string
    {
        return $this->validated('month') ?? self::defaultMonth();
    }

    private static function defaultMonth(): string
    {
        return CounsellorRecognition::decidableMonths()[0]->format('Y-m');
    }

    public static function monthRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (! CounsellorRecognition::parseMonth($value)) {
                $fail(CounsellorRecognition::MONTH_RULE);
            }
        };
    }
}
