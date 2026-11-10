<?php

namespace App\Enums;

use App\Traits\EnumTrait;

// SCRUM-340: a report's review lifecycle. open -> under_review (an admin started looking, or
// unlocked confidential messages) -> resolved | dismissed. A closed report can be reopened to
// under_review. Only an open/under_review report unlocks confidential messages.
enum ReportStatusEnum: string
{
    use EnumTrait;

    case open = 'open';
    case under_review = 'under_review';
    case resolved = 'resolved';
    case dismissed = 'dismissed';

    public function isActive(): bool
    {
        return in_array($this, [self::open, self::under_review], true);
    }

    /** @return array<int, self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::open => [self::under_review, self::resolved, self::dismissed],
            self::under_review => [self::resolved, self::dismissed],
            self::resolved, self::dismissed => [self::under_review],
        };
    }
}
