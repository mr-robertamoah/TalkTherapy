<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Enums\OrganizationCounsellorCompensationBasisEnum;
use App\Enums\OrganizationCounsellorCompensationTypeEnum;
use App\Models\OrganizationCounsellorCompensation;
use InvalidArgumentException;

// TT-7.3b-b0/SCRUM-232: the ONE place a counsellor's compensation-driven share of a session gets
// computed from their org's terms -- consumed by TT-7.3b-b (the org-charge primitive) and
// TT-7.3b-d (earnings-generation), so neither reimplements this FIXED/PERCENTAGE/FREE math
// independently. All amounts are minor units (pesewas/cents), matching this codebase's
// established money-handling convention -- integer arithmetic throughout, no floats.
class ComputeCounsellorCompensationShareAction extends Action
{
    // $counsellorListedAmount is the counsellor's own normal rate for this specific engagement,
    // in minor units -- required only when basis=COUNSELLOR_RATE (percentage of the counsellor's
    // own listed price); ignored otherwise. Callers resolve this themselves (e.g. via the
    // therapy/session's own payment_data amount) -- this action only does the compensation math,
    // never resolves what a counsellor's own rate is.
    public function execute(OrganizationCounsellorCompensation $compensation, ?int $counsellorListedAmount = null): int
    {
        return match ($compensation->type) {
            OrganizationCounsellorCompensationTypeEnum::free->value => 0,
            OrganizationCounsellorCompensationTypeEnum::fixed->value => $compensation->amount,
            OrganizationCounsellorCompensationTypeEnum::percentage->value => $this->computePercentageShare($compensation, $counsellorListedAmount),
            default => throw new InvalidArgumentException('Unrecognized compensation type.'),
        };
    }

    private function computePercentageShare(OrganizationCounsellorCompensation $compensation, ?int $counsellorListedAmount): int
    {
        $basisAmount = match ($compensation->basis) {
            OrganizationCounsellorCompensationBasisEnum::counsellorRate->value => $counsellorListedAmount
                ?? throw new InvalidArgumentException('The counsellor\'s own listed rate is required to compute a COUNSELLOR_RATE-basis share.'),
            OrganizationCounsellorCompensationBasisEnum::negotiatedRate->value => $compensation->negotiated_rate_amount
                ?? throw new InvalidArgumentException('This compensation has no negotiated rate amount recorded.'),
            default => throw new InvalidArgumentException('Unrecognized compensation basis.'),
        };

        // Basis-points conversion before multiplying against money, same convention as
        // TT-7.6b's platform-fee calculation -- avoids float drift from a naive percentage
        // multiplication.
        $basisPoints = (int) round($compensation->percentage * 100);

        // Defensive overflow guard (security-engineer finding): $basisAmount is validated at the
        // write boundary (CreateOrganizationCounsellorCompensationRequest caps negotiatedRateAmount
        // well below this), but this is "the ONE place this math happens" for every future caller
        // -- an absurdly large value slipping past that validation would otherwise silently
        // promote to a float here (PHP has no integer-overflow exception) and corrupt the result
        // rather than error, which is far worse for money math than a thrown exception.
        if ($basisAmount > 0 && $basisPoints > intdiv(PHP_INT_MAX, $basisAmount)) {
            throw new InvalidArgumentException('The compensation basis amount is too large to compute a percentage share safely.');
        }

        return intdiv($basisAmount * $basisPoints, 10000);
    }
}
