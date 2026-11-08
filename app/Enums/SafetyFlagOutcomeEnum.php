<?php

namespace App\Enums;

use App\Traits\EnumTrait;

// SCRUM-32 (TT-4.3b): how the handling administrator resolved a safety flag (the list the user accepted
// with the plan, 2026-11-08; pending the legal read). The flagging counsellor sees only "resolved".
enum SafetyFlagOutcomeEnum: string
{
    use EnumTrait;

    case referred = 'REFERRED';
    case emergencyServices = 'EMERGENCY_SERVICES';
    case safetyPlan = 'SAFETY_PLAN';
    case clientDeclined = 'CLIENT_DECLINED';
    case unreachable = 'UNREACHABLE';
    case notWarranted = 'NOT_WARRANTED';
    case other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::referred => 'Referred to specialist or inpatient care',
            self::emergencyServices => 'Emergency services attended',
            self::safetyPlan => 'Safety plan in place, therapy continues',
            self::clientDeclined => 'The client declined',
            self::unreachable => 'Couldn\'t reach the client',
            self::notWarranted => 'Not warranted',
            self::other => 'Other',
        };
    }
}
