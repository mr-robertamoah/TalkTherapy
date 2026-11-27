<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// SCRUM-479 (TT-9.3a): a translation key with no line at all is logged (outside production) -- once per key and
// language for each request or job (bound scoped in AppServiceProvider, so a long-lived worker starts afresh each
// time). The validator's own lookups for optional overrides (validation.custom.*, validation.attributes.*,
// validation.values.*) are expected to miss and aren't. In English, only a `file.key` style key is: text used as its
// own key (Lang::get('Hello!') in a mail) is English already -- the source language -- so it's a gap only in another.
//
// Limitation: Laravel only calls this once the fallback (English) has no line either. A `file.key` that English has
// but French or Twi lacks falls back to English silently and is not logged here -- per-language completeness is
// TT-9.3b's parity test (SCRUM-480), not this.
class MissingTranslationKeys
{
    private const PROBES = ['validation.custom.', 'validation.attributes.', 'validation.values.'];

    /** @var array<string, true> */
    private array $seen = [];

    public function report(string $key, ?string $locale): void
    {
        if (Str::startsWith($key, self::PROBES) || ($locale === Locales::DEFAULT && ! preg_match('/^[\w-]+(\.[\w-]+)+$/', $key))) {
            return;
        }

        $id = $locale.'|'.$key;
        if (isset($this->seen[$id])) {
            return;
        }
        $this->seen[$id] = true;

        Log::warning('Missing translation key.', ['key' => $key, 'locale' => $locale]);
    }
}
