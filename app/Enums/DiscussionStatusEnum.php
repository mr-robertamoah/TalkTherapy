<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum DiscussionStatusEnum : string {
    use EnumTrait;

    case pending = 'PENDING';
    case in_session = 'IN_SESSION'; // when having the discussion
    case failed = 'FAILED'; // when scheduled but not held
    case abandoned = 'ABANDONED'; // held but ended before end time
    case held = 'HELD';
}