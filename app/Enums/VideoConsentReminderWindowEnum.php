<?php

namespace App\Enums;

use Carbon\CarbonInterface;

// TT-3.1e-e/SCRUM-284: the two guardian video-consent reminder windows. SCRUM-376: an enum, so the
// sweep can't be asked for a window that doesn't exist; each carries its sent-at column and how far
// ahead it looks.
enum VideoConsentReminderWindowEnum: string
{
    case day_before = 'day_before';
    case hour_before = 'hour_before';

    public function sentAtColumn(): string
    {
        return "{$this->value}_sent_at";
    }

    public function upperBound(): CarbonInterface
    {
        return match ($this) {
            self::day_before => now()->addDay(),
            self::hour_before => now()->addHour(),
        };
    }
}
