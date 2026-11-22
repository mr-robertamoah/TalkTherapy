<?php

namespace App\Enums;

use App\Traits\EnumTrait;

// TT-7.11a/SCRUM-470: the rule that earned a counsellor a star (stars.source). A NULL source is a legacy star.
enum StarSourceEnum: string
{
    use EnumTrait;

    case sessionHeld = 'session_held';
    case discussionHeld = 'discussion_held';
    case postPublished = 'post_published';
    case postInteraction = 'post_interaction';

    // The existing stars.type the star is stored under -- the DB enum is not widened.
    public function starType(): StarTypeEnum
    {
        return match ($this) {
            self::sessionHeld, self::discussionHeld => StarTypeEnum::participation,
            self::postPublished, self::postInteraction => StarTypeEnum::contribution,
        };
    }
}
