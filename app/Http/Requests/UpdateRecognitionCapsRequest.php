<?php

namespace App\Http\Requests;

use App\Services\SettingsService;
use Illuminate\Foundation\Http\FormRequest;

// TT-7.11d2/SCRUM-474: the daily star caps (StarCapPolicy), all six at once -- whole numbers from 0 to their maximum
// (maxima()). Super administrators only: a 403 before validation, like every other change on the recognition page
// (unlike the platform-fee form's thin authorize()); UpdateSettingAction checks again.
class UpdateRecognitionCapsRequest extends FormRequest
{
    public const MAX = 100;

    // Security review L2: the caps that stop one client or one group farming a counsellor's stars stay small.
    public const MAX_PER_SOURCE = 10;

    private const PER_SOURCE = ['session_per_client', 'session_per_group'];

    // Each cap's name => the most it may be set to.
    public static function maxima(): array
    {
        return collect(SettingsService::RECOGNITION_CAP_KEYS)
            ->keys()
            ->mapWithKeys(fn (string $name) => [$name => in_array($name, self::PER_SOURCE, true) ? self::MAX_PER_SOURCE : self::MAX])
            ->all();
    }

    public function authorize(): bool
    {
        return (bool) $this->user()?->isSuperAdmin();
    }

    public function rules(): array
    {
        $maxima = self::maxima();
        $rules = ['caps' => ['required', 'array:'.implode(',', array_keys($maxima))]];

        foreach ($maxima as $name => $max) {
            $rules["caps.{$name}"] = ['required', 'integer', 'min:0', "max:{$max}"];
        }

        return $rules;
    }

    public function attributes(): array
    {
        return collect(SettingsService::RECOGNITION_CAP_KEYS)
            ->keys()
            ->mapWithKeys(fn (string $name) => ["caps.{$name}" => str_replace('_', ' ', $name).' limit'])
            ->all();
    }
}
