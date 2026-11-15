<?php

namespace App\Enums;

use App\Traits\EnumTrait;

// SCRUM-445: a client's or member's attendance at an in-person session, as a counsellor marked it.
enum SessionAttendanceStatusEnum: string
{
    use EnumTrait;

    case present = 'PRESENT';
    case absent = 'ABSENT';
}
