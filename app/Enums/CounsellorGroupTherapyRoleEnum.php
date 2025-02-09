<?php

namespace App\Enums;
use App\Traits\EnumTrait;

enum CounsellorGroupTherapyRoleEnum : string {
    use EnumTrait;

    case normal = 'NORMAL';
    case coordinator = 'COORDINATOR';
    case admin = 'ADMIN';
}