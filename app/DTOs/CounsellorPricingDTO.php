<?php

namespace App\DTOs;

use App\Models\Counsellor;
use App\Models\User;
use MrRobertAmoah\DTO\BaseDTO;

class CounsellorPricingDTO extends BaseDTO
{
    public ?User $user = null;

    public ?Counsellor $counsellor = null;

    // Each entry: ['therapyType' => ?string, 'sessionType' => ?string, 'per' => ?string,
    // 'amount' => int, 'currency' => string]. A single entry with all three scope keys null is
    // the flat-rate mode; two or more entries, each fully specifying all three, is override mode
    // -- see EnsureCounsellorPricingDataIsValidAction for the rule this shape must satisfy.
    public array $pricings = [];
}
