<?php

namespace App\Http\Requests;

use App\Services\SettingsService;
use Illuminate\Foundation\Http\FormRequest;

// TT-7.11d2/SCRUM-474: the daily star caps (StarCapPolicy), all six at once -- whole numbers from 0 to MAX. Super
// administrators only: a 403 before validation, like every other change on the recognition page (unlike the
// platform-fee form's thin authorize()); UpdateSettingAction checks again.
class UpdateRecognitionCapsRequest extends FormRequest
{
    public const MAX = 100;

    public function authorize(): bool
    {
        return (bool) $this->user()?->isSuperAdmin();
    }

    public function rules(): array
    {
        $names = array_keys(SettingsService::RECOGNITION_CAP_KEYS);
        $rules = ['caps' => ['required', 'array:'.implode(',', $names)]];

        foreach ($names as $name) {
            $rules["caps.{$name}"] = ['required', 'integer', 'min:0', 'max:'.self::MAX];
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
