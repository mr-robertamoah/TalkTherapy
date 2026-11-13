<?php

namespace App\Enums;

use App\Traits\EnumTrait;

// SCRUM-313/329: what a guardian's video consent lets their ward do in a call -- watch only (join receive-only), or
// speak too (be given the floor in a group). Every grant made before this is SPEAK: one-to-one consent has always
// meant taking full part in the call.
enum VideoConsentLevelEnum: string
{
    use EnumTrait;

    case watch = 'WATCH';
    case speak = 'SPEAK';
}
