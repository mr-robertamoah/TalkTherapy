<?php

namespace App\Actions\Safety;

use App\Enums\SafetyFlagKindEnum;
use App\Models\GroupTherapy;
use App\Models\Therapy;
use App\Models\User;

// SCRUM-32: who a flag is about and where it was raised -- what EnsureCanFlagClientAction resolved.
final class SafetyFlagSubject
{
    public function __construct(
        public readonly Therapy|GroupTherapy $flaggable,
        public readonly User $client,
        public readonly SafetyFlagKindEnum $kind,
        public readonly bool $anonymous,
        public readonly ?int $membershipId = null,
    ) {}
}
