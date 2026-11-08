<?php

namespace App\Enums;

use App\Traits\EnumTrait;

// SCRUM-32 (TT-4.3b): what the handling administrator did about a safety flag -- recorded in its
// history. "Other" needs a few words.
enum SafetyFlagActionEnum: string
{
    use EnumTrait;

    case contactedCounsellor = 'CONTACTED_COUNSELLOR';
    case contactedClient = 'CONTACTED_CLIENT';
    case contactedGuardian = 'CONTACTED_GUARDIAN';
    case contactedEmergencyServices = 'CONTACTED_EMERGENCY_SERVICES';
    case referredToFacility = 'REFERRED_TO_FACILITY';
    case other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::contactedCounsellor => 'Contacted the counsellor',
            self::contactedClient => 'Contacted the client',
            self::contactedGuardian => 'Contacted a guardian',
            self::contactedEmergencyServices => 'Contacted emergency services',
            self::referredToFacility => 'Referred to a facility',
            self::other => 'Other',
        };
    }
}
